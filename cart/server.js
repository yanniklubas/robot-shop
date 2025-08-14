const instana = require("@instana/collector");
// init tracing
// MUST be done before loading anything else!
instana({
	tracing: {
		enabled: true,
	},
});

const redis = require("redis");
const request = require("request");
const bodyParser = require("body-parser");
const express = require("express");
const pino = require("pino");
const expPino = require("express-pino-logger");
const fs = require("fs");
// Prometheus
const promClient = require("prom-client");
const Registry = promClient.Registry;
const register = new Registry();
const counter = new promClient.Counter({
	name: "items_added",
	help: "running count of items added to cart",
	registers: [register],
});

var redisConnected = false;

var redisHost = process.env.REDIS_HOST || "redis";
var catalogueHost = process.env.CATALOGUE_HOST || "catalogue";

const logger = pino({
	level: "warn",
	prettyPrint: false,
	useLevelLabels: true,
});
const expLogger = expPino({
	logger: logger,
});

const app = express();

app.use(expLogger);

app.use((req, res, next) => {
	res.set("Timing-Allow-Origin", "*");
	res.set("Access-Control-Allow-Origin", "*");
	next();
});

app.use((req, res, next) => {
	let dcs = [
		"asia-northeast2",
		"asia-south1",
		"europe-west3",
		"us-east1",
		"us-west1",
	];
	let span = instana.currentSpan();
	span.annotate(
		"custom.sdk.tags.datacenter",
		dcs[Math.floor(Math.random() * dcs.length)],
	);

	next();
});

app.use(bodyParser.urlencoded({ extended: true }));
app.use(bodyParser.json());

app.get("/health", (req, res) => {
	var stat = {
		app: "OK",
		redis: redisConnected,
	};
	res.json(stat);
});

// fault injection code that does not cause liveness probe to fail
app.use((req, res, next) => {
	if (req.path !== "/health" && fs.existsSync("/tmp/faults.txt")) {
		setTimeout(() => {
			res.status(503).send("Fault injection triggered");
		}, 500);
	} else {
		next();
	}
});
// Prometheus
app.get("/metrics", (req, res) => {
	res.header("Content-Type", "text/plain");
	res.send(register.metrics());
});

// get cart with id
app.get("/cart/:id", (req, res) => {
	redisClient.get(req.params.id, (err, data) => {
		if (err) {
			req.log.error("ERROR", err);
			res.status(500).send(err);
		} else {
			if (data == null) {
				res.status(404).send("cart not found");
			} else {
				res.set("Content-Type", "application/json");
				res.send(data);
			}
		}
	});
});

// delete cart with id
app.delete("/cart/:id", (req, res) => {
	redisClient.del(req.params.id, (err, data) => {
		if (err) {
			req.log.error("ERROR", err);
			res.status(500).send(err);
		} else {
			if (data == 1) {
				res.send("OK");
			} else {
				res.status(404).send("cart not found");
			}
		}
	});
});

// rename cart i.e. at login
app.get("/rename/:from/:to", (req, res) => {
	redisClient.get(req.params.from, (err, data) => {
		if (err) {
			req.log.error("ERROR", err);
			res.status(500).send(err);
		} else {
			if (data == null) {
				res.status(404).send("cart not found");
			} else {
				var cart = JSON.parse(data);
				saveCart(req.params.to, cart)
					.then((data) => {
						res.json(cart);
					})
					.catch((err) => {
						req.log.error(err);
						res.status(500).send(err);
					});
			}
		}
	});
});

// update/create cart
app.get("/add/:id/:sku/:qty", (req, res) => {
	// check quantity
	var qty = parseInt(req.params.qty);
	if (isNaN(qty)) {
		req.log.warn("quantity not a number");
		res.status(400).send("quantity must be a number");
		return;
	} else if (qty < 1) {
		req.log.warn("quantity less than one");
		res.status(400).send("quantity has to be greater than zero");
		return;
	}

	// look up product details
	getProduct(req.params.sku)
		.then((product) => {
			req.log.info("got product", product);
			if (!product) {
				res.status(404).send("product not found");
				return;
			}
			// is the product in stock?
			if (product.instock == 0) {
				res.status(404).send("out of stock");
				return;
			}
			// does the cart already exist?
			redisClient.get(req.params.id, (err, data) => {
				if (err) {
					req.log.error("ERROR", err);
					res.status(500).send(err);
				} else {
					var cart;
					if (data == null) {
						// create new cart
						cart = {
							total: 0,
							tax: 0,
							items: [],
						};
					} else {
						cart = JSON.parse(data);
					}
					req.log.info("got cart", cart);
					// add sku to cart
					var item = {
						qty: qty,
						sku: req.params.sku,
						name: product.name,
						price: product.price,
						subtotal: qty * product.price,
					};
					var list = mergeList(cart.items, item, qty);
					cart.items = list;
					cart.total = calcTotal(cart.items);
					// work out tax
					cart.tax = calcTax(cart.total);

					// save the new cart
					saveCart(req.params.id, cart)
						.then((data) => {
							counter.inc(qty);
							res.json(cart);
						})
						.catch((err) => {
							req.log.error(err);
							res.status(500).send(err);
						});
				}
			});
		})
		.catch((err) => {
			// req.log.error(err);
			res.status(500).send(err);
		});
});

// update quantity - remove item when qty == 0
app.get("/update/:id/:sku/:qty", (req, res) => {
	// check quantity
	var qty = parseInt(req.params.qty);
	if (isNaN(qty)) {
		req.log.warn("quanity not a number");
		res.status(400).send("quantity must be a number");
		return;
	} else if (qty < 0) {
		req.log.warn("quantity less than zero");
		res.status(400).send("negative quantity not allowed");
		return;
	}

	// get the cart
	redisClient.get(req.params.id, (err, data) => {
		if (err) {
			req.log.error("ERROR", err);
			res.status(500).send(err);
		} else {
			if (data == null) {
				res.status(404).send("cart not found");
			} else {
				var cart = JSON.parse(data);
				var idx;
				var len = cart.items.length;
				for (idx = 0; idx < len; idx++) {
					if (cart.items[idx].sku == req.params.sku) {
						break;
					}
				}
				if (idx == len) {
					// not in list
					res.status(404).send("not in cart");
				} else {
					if (qty == 0) {
						cart.items.splice(idx, 1);
					} else {
						cart.items[idx].qty = qty;
						cart.items[idx].subtotal = cart.items[idx].price * qty;
					}
					cart.total = calcTotal(cart.items);
					// work out tax
					cart.tax = calcTax(cart.total);
					saveCart(req.params.id, cart)
						.then((data) => {
							res.json(cart);
						})
						.catch((err) => {
							req.log.error(err);
							res.status(500).send(err);
						});
				}
			}
		}
	});
});

// add shipping
app.post("/shipping/:id", (req, res) => {
	var shipping = req.body;
	if (
		shipping.distance === undefined ||
		shipping.cost === undefined ||
		shipping.location == undefined
	) {
		req.log.warn("shipping data missing", shipping);
		res.status(400).send("shipping data missing");
	} else {
		// get the cart
		redisClient.get(req.params.id, (err, data) => {
			if (err) {
				req.log.error("ERROR", err);
				res.status(500).send(err);
			} else {
				if (data == null) {
					req.log.info("no cart for", req.params.id);
					res.status(404).send("cart not found");
				} else {
					var cart = JSON.parse(data);
					var item = {
						qty: 1,
						sku: "SHIP",
						name: "shipping to " + shipping.location,
						price: shipping.cost,
						subtotal: shipping.cost,
					};
					// check shipping already in the cart
					var idx;
					var len = cart.items.length;
					for (idx = 0; idx < len; idx++) {
						if (cart.items[idx].sku == item.sku) {
							break;
						}
					}
					if (idx == len) {
						// not already in cart
						cart.items.push(item);
					} else {
						cart.items[idx] = item;
					}
					cart.total = calcTotal(cart.items);
					// work out tax
					cart.tax = calcTax(cart.total);

					// save the updated cart
					saveCart(req.params.id, cart)
						.then((data) => {
							res.json(cart);
						})
						.catch((err) => {
							req.log.error(err);
							res.status(500).send(err);
						});
				}
			}
		});
	}
});

function mergeList(list, product, qty) {
	var inlist = false;
	// loop through looking for sku
	var idx;
	var len = list.length;
	for (idx = 0; idx < len; idx++) {
		if (list[idx].sku == product.sku) {
			inlist = true;
			break;
		}
	}

	if (inlist) {
		list[idx].qty += qty;
		list[idx].subtotal = list[idx].price * list[idx].qty;
	} else {
		list.push(product);
	}

	return list;
}

function calcTotal(list) {
	var total = 0;
	for (var idx = 0, len = list.length; idx < len; idx++) {
		total += list[idx].subtotal;
	}

	return total;
}

function calcTax(total) {
	// tax @ 20%
	return total - total / 1.2;
}

class CircuitBreaker {
	constructor(options = {}) {
		this.maxConcurrent = options.maxConcurrent || 10;
		this.failureThreshold = options.failureThreshold || 0.5; // 50%
		this.timeout = options.timeout || 60000; // 1 minute
		this.resetTimeout = options.resetTimeout || 30000; // 30 seconds
		this.maxTimestamps = options.maxTimestamps || 1000;

		this.state = "CLOSED"; // CLOSED, OPEN, HALF_OPEN
		this.successTimestamps = [];
		this.nextAttempt = Date.now();
		this.lastCleanTime = Date.now();
		this.activeRequests = 0;
		this.failureTimestamps = [];
	}

	// Check if request should be allowed
	canRequest() {
		// Check concurrent limit
		if (this.activeRequests >= this.maxConcurrent) {
			return false;
		}

		// If circuit is open, check if we should move to half-open
		if (this.state === "OPEN") {
			if (Date.now() >= this.nextAttempt) {
				logger.warn(
					`CircuitBreaker: switching from ${this.state} to HALF_OPEN`,
				);
				this.state = "HALF_OPEN";
				return true;
			}
			return false;
		}

		// In HALF_OPEN, only allow one request
		if (this.state === "HALF_OPEN") {
			return this.activeRequests === 0;
		}

		return true;
	}

	// Helper method to clean old timestamps
	cleanOldTimestamps(timestamps, now) {
		let i = 0;
		while (i < timestamps.length && now - timestamps[i] > this.timeout) {
			i++;
		}
		return timestamps.slice(i);
	}

	cleanTimestampsIfNeeded(now) {
		let shouldUpdateCleanTime = false;
		// Force cleanup if arrays are too large
		if (this.successTimestamps.length > this.maxTimestamps) {
			this.successTimestamps = this.cleanOldTimestamps(
				this.successTimestamps,
				now,
			);
			shouldUpdateCleanTime = true;
		}
		if (this.failureTimestamps.length > this.maxTimestamps) {
			this.failureTimestamps = this.cleanOldTimestamps(
				this.failureTimestamps,
				now,
			);
			shouldUpdateCleanTime = true;
		}

		// Only clean if we're about to calculate failure rate or if it's been a while
		if (now - this.lastCleanTime > Math.max(this.timeout / 50, 1000)) {
			// at most every second
			// Clean every 50% of timeout
			this.successTimestamps = this.cleanOldTimestamps(
				this.successTimestamps,
				now,
			);
			this.failureTimestamps = this.cleanOldTimestamps(
				this.failureTimestamps,
				now,
			);
			shouldUpdateCleanTime = true;
		}
		if (shouldUpdateCleanTime) {
			this.lastCleanTime = Date.now();
		}
	}

	// Record a successful request
	recordSuccess() {
		this.activeRequests = Math.max(0, this.activeRequests - 1);
		const now = Date.now();
		this.successTimestamps.push(now);

		this.cleanTimestampsIfNeeded(now);

		if (this.state === "HALF_OPEN") {
			logger.warn(`CircuitBreaker: switching from ${this.state} to CLOSED`);
			this.state = "CLOSED";
			this.resetMetrics();
		}
	}

	// Record a failed request
	recordFailure() {
		this.activeRequests = Math.max(0, this.activeRequests - 1);

		const now = Date.now();
		this.failureTimestamps.push(now);

		// Remove old failures and successes outside the time window
		this.failureTimestamps = this.cleanOldTimestamps(
			this.failureTimestamps,
			now,
		);
		this.successTimestamps = this.cleanOldTimestamps(
			this.successTimestamps,
			now,
		);
		// Calculate failure rate within the time window
		const recentFailures = this.failureTimestamps.length;
		const recentTotal =
			this.failureTimestamps.length + this.successTimestamps.length;

		const failureRate = recentTotal > 0 ? recentFailures / recentTotal : 0;

		// Check if we should open the circuit
		if (this.state === "HALF_OPEN") {
			this.state = "OPEN";
			this.nextAttempt = Date.now() + this.resetTimeout;
			logger.warn(`CircuitBreaker OPENED: ${JSON.stringify(this.getStatus())}`);
		} else if (
			this.state === "CLOSED" &&
			failureRate >= this.failureThreshold
		) {
			this.state = "OPEN";
			this.nextAttempt = Date.now() + this.resetTimeout;
			logger.warn(`CircuitBreaker OPENED: ${JSON.stringify(this.getStatus())}`);
		}
	}

	// Reset metrics when circuit closes
	resetMetrics() {
		this.successTimestamps = [];
		this.failureTimestamps = [];
	}

	// Execute a function with circuit breaker protection
	async execute(fn, isFailure = null) {
		if (!this.canRequest()) {
			throw new Error(
				`Circuit breaker is ${this.state} or max concurrent requests reached`,
			);
		}

		this.activeRequests++;

		try {
			const result = await fn();
			if (isFailure && isFailure(result)) {
				this.recordFailure();
				return result;
			}
			this.recordSuccess();
			return result;
		} catch (error) {
			this.recordFailure();
			throw error;
		}
	}

	getStatus() {
		let totalRequests =
			this.failureTimestamps.length + this.successTimestamps.length;
		return {
			state: this.state,
			failureCount: this.failureTimestamps.length,
			successCount: this.successTimestamps.length,
			totalRequests: totalRequests,
			activeRequests: this.activeRequests,
			failureRate:
				totalRequests > 0 ? this.failureTimestamps.length / totalRequests : 0,
		};
	}
}

const productCircuitBreaker = new CircuitBreaker({
	maxConcurrent: Number.MAX_SAFE_INTEGER,
	failureThreshold: 0.1,
	timeout: 300_000, //ms, 300s
	resetTimeout: 300_000, //ms, 300s
});

function getProduct(sku) {
	return productCircuitBreaker.execute(
		() => {
			return new Promise((resolve, reject) => {
				request(
					"http://" + catalogueHost + ":8080/product/" + sku,
					(err, res, body) => {
						if (err) {
							reject(err);
						} else if (res.statusCode != 200) {
							resolve(null);
						} else {
							try {
								// Handle JSON parse errors
								resolve(JSON.parse(body));
							} catch (parseError) {
								reject(parseError);
							}
						}
					},
				);
			});
		},
		(result) => result === null,
	);
}

function saveCart(id, cart) {
	logger.info("saving cart", cart);
	return new Promise((resolve, reject) => {
		redisClient.setex(id, 3600, JSON.stringify(cart), (err, data) => {
			if (err) {
				reject(err);
			} else {
				resolve(data);
			}
		});
	});
}

// connect to Redis
var redisClient = redis.createClient({
	host: redisHost,
});

redisClient.on("error", (e) => {
	logger.error("Redis ERROR", e);
});
redisClient.on("ready", (r) => {
	logger.info("Redis READY", r);
	redisConnected = true;
});

// fire it up!
const port = process.env.CART_SERVER_PORT || "8080";
app.listen(port, () => {
	logger.info("Started on port", port);
});
