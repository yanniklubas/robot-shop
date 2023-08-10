#!/bin/sh

NS="robot-shop"
DEPLOYMENTS="cart catalogue dispatch payment ratings shipping user web mysql mongodb"

for DEP in $DEPLOYMENTS
do
    kubectl -n $NS delete hpa $DEP
    kubectl -n $NS autoscale deployment $DEP --max 200 --min 1 --cpu-percent 50
done

echo "Waiting 5 seconds for changes to apply..."
sleep 5
kubectl -n $NS get hpa

