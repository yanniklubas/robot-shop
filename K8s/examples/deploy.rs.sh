#!/bin/bash

ROBOTSHOP_NAMESPACE_PROJECT_NAME="robot-shop" 

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"

if [ "$1" = "start" ] ; then
    kubectl create namespace $ROBOTSHOP_NAMESPACE_PROJECT_NAME
    helm install "${ROBOTSHOP_NAMESPACE_PROJECT_NAME}" --namespace "${ROBOTSHOP_NAMESPACE_PROJECT_NAME}"  --create-namespace ${SCRIPT_DIR}/../helm  --values ${SCRIPT_DIR}/rs.yaml

elif [ "$1" = "stop" ] ; then
    kubectl delete rabbitmqclusters --all -n robot-shop
    kubectl delete svc -n robot-shop rabbitmq rabbitmq-nodes
    kubectl delete statefulset -n robot-shop rabbitmq-server
    helm uninstall "${ROBOTSHOP_NAMESPACE_PROJECT_NAME}" --namespace "${ROBOTSHOP_NAMESPACE_PROJECT_NAME}" 
fi
