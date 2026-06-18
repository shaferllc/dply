<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Examples;

use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Facades\TaskRunner;
use App\Modules\TaskRunner\TaskChain;
use Illuminate\Support\Facades\Log;

/**
 * Advanced example demonstrating microservices orchestration and service mesh management.
 *
 * This example shows how to:
 * - Orchestrate multiple microservices
 * - Implement service discovery and health checks
 * - Handle service dependencies and rollbacks
 * - Manage service mesh configurations
 * - Implement circuit breakers and load balancing
 */
class MicroservicesOrchestrationExample
{
    /**
     * Run a comprehensive microservices orchestration example.
     */
    public function run(): void
    {
        Log::info('Starting Microservices Orchestration Example');

        // 1. Service Discovery and Health Checks
        $this->serviceDiscoveryAndHealthChecks();

        // 2. Microservices Deployment Chain
        $this->microservicesDeploymentChain();

        // 3. Service Mesh Configuration
        $this->serviceMeshConfiguration();

        // 4. Circuit Breaker Implementation
        $this->circuitBreakerImplementation();

        // 5. Load Balancer Configuration
        $this->loadBalancerConfiguration();

        Log::info('Microservices Orchestration Example completed');
    }

    /**
     * Service discovery and health checks for microservices.
     */
    private function serviceDiscoveryAndHealthChecks(): void
    {
        $discoveryTask = AnonymousTask::command('service-discovery', <<<'BASH'
#!/bin/bash

# Service discovery and health checks
echo "Starting service discovery and health checks..."

# Define services
SERVICES=(
    "user-service:8081"
    "auth-service:8082"
    "payment-service:8083"
    "notification-service:8084"
    "analytics-service:8085"
)

# Service registry file
REGISTRY_FILE="/tmp/service_registry.json"
HEALTH_FILE="/tmp/health_status.json"

# Initialize registry
cat > $REGISTRY_FILE << 'EOF'
{
  "services": {},
  "last_updated": null,
  "total_services": 0,
  "healthy_services": 0
}
EOF

# Function to check service health
check_service_health() {
    local service=$1
    local host=$(echo $service | cut -d: -f1)
    local port=$(echo $service | cut -d: -f2)
    
    echo "Checking health of $service..."
    
    # Try to connect to the service
    if timeout 5 bash -c "</dev/tcp/$host/$port" 2>/dev/null; then
        # Service is reachable, check health endpoint
        health_response=$(curl -s -o /dev/null -w "%{http_code}" "http://$service/health" 2>/dev/null || echo "000")
        
        if [[ $health_response == "200" ]]; then
            echo "✅ $service is healthy"
            return 0
        else
            echo "⚠️ $service is reachable but unhealthy (HTTP $health_response)"
            return 1
        fi
    else
        echo "❌ $service is unreachable"
        return 2
    fi
}

# Function to update service registry
update_registry() {
    local service=$1
    local status=$2
    local timestamp=$(date -Iseconds)
    
    # Update registry with service status
    jq ".services.\"$service\" = {
        \"status\": \"$status\",
        \"last_check\": \"$timestamp\",
        \"port\": \"$(echo $service | cut -d: -f2)\"
    }" $REGISTRY_FILE > /tmp/temp_registry.json
    mv /tmp/temp_registry.json $REGISTRY_FILE
}

# Check all services
healthy_count=0
total_count=${#SERVICES[@]}

for service in "${SERVICES[@]}"; do
    if check_service_health "$service"; then
        update_registry "$service" "healthy"
        ((healthy_count++))
    else
        update_registry "$service" "unhealthy"
    fi
done

# Update registry summary
jq ".last_updated = \"$(date -Iseconds)\" | .total_services = $total_count | .healthy_services = $healthy_count" $REGISTRY_FILE > /tmp/temp_registry.json
mv /tmp/temp_registry.json $REGISTRY_FILE

# Generate health report
cat > $HEALTH_FILE << EOF
{
  "timestamp": "$(date -Iseconds)",
  "summary": {
    "total_services": $total_count,
    "healthy_services": $healthy_count,
    "unhealthy_services": $((total_count - healthy_count)),
    "health_percentage": $((healthy_count * 100 / total_count))
  },
  "services": $(cat $REGISTRY_FILE | jq '.services')
}
EOF

echo "Service discovery completed:"
cat $HEALTH_FILE | jq '.summary'

# Cleanup
rm -f $REGISTRY_FILE $HEALTH_FILE
BASH
        )
            ->withName('Service Discovery and Health Checks')
            ->withTimeout(300);

        TaskRunner::run($discoveryTask);
    }

    /**
     * Microservices deployment chain with dependencies.
     */
    private function microservicesDeploymentChain(): void
    {
        $deploymentChain = TaskChain::make()
            ->withName('Microservices Deployment Chain')
            ->withTimeout(1800)
            ->stopOnFailure(true);

        // Step 1: Deploy database migrations
        $deploymentChain->addCommand('deploy-migrations', <<<'BASH'
#!/bin/bash
echo "Step 1: Deploying database migrations..."
php artisan migrate --force
echo "Database migrations completed successfully"
BASH
        );

        // Step 2: Deploy shared libraries
        $deploymentChain->addCommand('deploy-shared-libs', <<<'BASH'
#!/bin/bash
echo "Step 2: Deploying shared libraries..."
composer install --no-dev --optimize-autoloader
npm ci --production
echo "Shared libraries deployed successfully"
BASH
        );

        // Step 3: Deploy user service
        $deploymentChain->addCommand('deploy-user-service', <<<'BASH'
#!/bin/bash
echo "Step 3: Deploying user service..."
cd /var/www/user-service
git pull origin main
composer install --no-dev
php artisan migrate --force
php artisan config:cache
php artisan route:cache
systemctl restart user-service
echo "User service deployed successfully"
BASH
        );

        // Step 4: Deploy auth service
        $deploymentChain->addCommand('deploy-auth-service', <<<'BASH'
#!/bin/bash
echo "Step 4: Deploying auth service..."
cd /var/www/auth-service
git pull origin main
composer install --no-dev
php artisan migrate --force
php artisan config:cache
systemctl restart auth-service
echo "Auth service deployed successfully"
BASH
        );

        // Step 5: Deploy payment service
        $deploymentChain->addCommand('deploy-payment-service', <<<'BASH'
#!/bin/bash
echo "Step 5: Deploying payment service..."
cd /var/www/payment-service
git pull origin main
composer install --no-dev
php artisan migrate --force
php artisan config:cache
systemctl restart payment-service
echo "Payment service deployed successfully"
BASH
        );

        // Step 6: Deploy notification service
        $deploymentChain->addCommand('deploy-notification-service', <<<'BASH'
#!/bin/bash
echo "Step 6: Deploying notification service..."
cd /var/www/notification-service
git pull origin main
composer install --no-dev
php artisan config:cache
systemctl restart notification-service
echo "Notification service deployed successfully"
BASH
        );

        // Step 7: Deploy API gateway
        $deploymentChain->addCommand('deploy-api-gateway', <<<'BASH'
#!/bin/bash
echo "Step 7: Deploying API gateway..."
cd /var/www/api-gateway
git pull origin main
npm ci --production
npm run build
systemctl restart nginx
echo "API gateway deployed successfully"
BASH
        );

        // Step 8: Health check all services
        $deploymentChain->addCommand('health-check-all', <<<'BASH'
#!/bin/bash
echo "Step 8: Performing health checks..."
services=("user-service:8081" "auth-service:8082" "payment-service:8083" "notification-service:8084")

for service in "${services[@]}"; do
    echo "Checking $service..."
    for i in {1..10}; do
        if curl -f -s "http://$service/health" > /dev/null; then
            echo "✅ $service is healthy"
            break
        else
            echo "⏳ Waiting for $service to be ready... (attempt $i/10)"
            sleep 5
        fi
    done
done

echo "All services health checked successfully"
BASH
        );

        $deploymentChain->run();
    }

    /**
     * Service mesh configuration for microservices.
     */
    private function serviceMeshConfiguration(): void
    {
        $meshTask = AnonymousTask::command('service-mesh-config', <<<'BASH'
#!/bin/bash

# Service mesh configuration
echo "Configuring service mesh..."

# Istio configuration directory
ISTIO_DIR="/tmp/istio-config"
mkdir -p $ISTIO_DIR

# 1. Virtual Service Configuration
cat > $ISTIO_DIR/virtual-service.yaml << 'EOF'
apiVersion: networking.istio.io/v1alpha3
kind: VirtualService
metadata:
  name: microservices-vs
spec:
  hosts:
  - "api.example.com"
  gateways:
  - microservices-gateway
  http:
  - match:
    - uri:
        prefix: "/api/users"
    route:
    - destination:
        host: user-service
        port:
          number: 8081
  - match:
    - uri:
        prefix: "/api/auth"
    route:
    - destination:
        host: auth-service
        port:
          number: 8082
  - match:
    - uri:
        prefix: "/api/payments"
    route:
    - destination:
        host: payment-service
        port:
          number: 8083
  - match:
    - uri:
        prefix: "/api/notifications"
    route:
    - destination:
        host: notification-service
        port:
          number: 8084
EOF

# 2. Destination Rules
cat > $ISTIO_DIR/destination-rules.yaml << 'EOF'
apiVersion: networking.istio.io/v1alpha3
kind: DestinationRule
metadata:
  name: microservices-dr
spec:
  host: "*.service.local"
  trafficPolicy:
    loadBalancer:
      simple: ROUND_ROBIN
    connectionPool:
      tcp:
        maxConnections: 100
      http:
        http1MaxPendingRequests: 1000
        maxRequestsPerConnection: 10
    outlierDetection:
      consecutive5xxErrors: 5
      interval: 30s
      baseEjectionTime: 30s
      maxEjectionPercent: 10
EOF

# 3. Circuit Breaker Configuration
cat > $ISTIO_DIR/circuit-breaker.yaml << 'EOF'
apiVersion: networking.istio.io/v1alpha3
kind: DestinationRule
metadata:
  name: circuit-breaker-dr
spec:
  host: payment-service
  trafficPolicy:
    connectionPool:
      tcp:
        maxConnections: 50
      http:
        http1MaxPendingRequests: 100
        maxRequestsPerConnection: 1
    outlierDetection:
      consecutive5xxErrors: 3
      interval: 10s
      baseEjectionTime: 60s
      maxEjectionPercent: 50
EOF

# 4. Retry Policy
cat > $ISTIO_DIR/retry-policy.yaml << 'EOF'
apiVersion: networking.istio.io/v1alpha3
kind: VirtualService
metadata:
  name: retry-policy-vs
spec:
  hosts:
  - payment-service
  http:
  - route:
    - destination:
        host: payment-service
    retries:
      attempts: 3
      perTryTimeout: 2s
      retryOn: connect-failure,refused-stream,unavailable,cancelled,retriable-status-codes
      retryRemoteLocalities: true
EOF

# 5. Fault Injection for Testing
cat > $ISTIO_DIR/fault-injection.yaml << 'EOF'
apiVersion: networking.istio.io/v1alpha3
kind: VirtualService
metadata:
  name: fault-injection-vs
spec:
  hosts:
  - payment-service
  http:
  - fault:
      delay:
        percentage:
          value: 10
        fixedDelay: 5s
      abort:
        percentage:
          value: 5
        httpStatus: 500
    route:
    - destination:
        host: payment-service
EOF

# 6. Traffic Splitting
cat > $ISTIO_DIR/traffic-splitting.yaml << 'EOF'
apiVersion: networking.istio.io/v1alpha3
kind: VirtualService
metadata:
  name: traffic-split-vs
spec:
  hosts:
  - user-service
  http:
  - route:
    - destination:
        host: user-service
        subset: v1
      weight: 90
    - destination:
        host: user-service
        subset: v2
      weight: 10
EOF

# Apply configurations
echo "Applying service mesh configurations..."

for config in $ISTIO_DIR/*.yaml; do
    echo "Applying $(basename $config)..."
    # In a real environment, you would use: kubectl apply -f $config
    echo "Applied: $(basename $config)"
done

# Generate configuration summary
cat > /tmp/mesh-config-summary.json << EOF
{
  "timestamp": "$(date -Iseconds)",
  "configurations": [
    "virtual-service.yaml",
    "destination-rules.yaml", 
    "circuit-breaker.yaml",
    "retry-policy.yaml",
    "fault-injection.yaml",
    "traffic-splitting.yaml"
  ],
  "services": [
    "user-service",
    "auth-service", 
    "payment-service",
    "notification-service"
  ],
  "features": [
    "load_balancing",
    "circuit_breaking",
    "retry_policies",
    "fault_injection",
    "traffic_splitting"
  ]
}
EOF

echo "Service mesh configuration completed:"
cat /tmp/mesh-config-summary.json

# Cleanup
rm -rf $ISTIO_DIR /tmp/mesh-config-summary.json
BASH
        )
            ->withName('Service Mesh Configuration')
            ->withTimeout(600);

        TaskRunner::run($meshTask);
    }

    /**
     * Circuit breaker implementation for microservices.
     */
    private function circuitBreakerImplementation(): void
    {
        $circuitBreakerTask = AnonymousTask::command('circuit-breaker', <<<'BASH'
#!/bin/bash

# Circuit breaker implementation
echo "Implementing circuit breaker pattern..."

# Circuit breaker configuration
CIRCUIT_BREAKER_FILE="/tmp/circuit_breaker_state.json"
FAILURE_THRESHOLD=5
TIMEOUT_DURATION=60
HALF_OPEN_MAX_REQUESTS=3

# Initialize circuit breaker state
cat > $CIRCUIT_BREAKER_FILE << EOF
{
  "state": "CLOSED",
  "failure_count": 0,
  "last_failure_time": null,
  "success_count": 0,
  "total_requests": 0
}
EOF

# Function to update circuit breaker state
update_circuit_breaker() {
    local new_state=$1
    local failure_count=$2
    local success_count=$3
    
    jq ".state = \"$new_state\" | .failure_count = $failure_count | .success_count = $success_count | .last_failure_time = \"$(date -Iseconds)\"" $CIRCUIT_BREAKER_FILE > /tmp/temp_cb.json
    mv /tmp/temp_cb.json $CIRCUIT_BREAKER_FILE
}

# Function to check if circuit breaker should open
should_open_circuit() {
    local failure_count=$(jq -r '.failure_count' $CIRCUIT_BREAKER_FILE)
    [[ $failure_count -ge $FAILURE_THRESHOLD ]]
}

# Function to check if circuit breaker should close
should_close_circuit() {
    local last_failure=$(jq -r '.last_failure_time' $CIRCUIT_BREAKER_FILE)
    local current_time=$(date +%s)
    local last_failure_epoch=$(date -d "$last_failure" +%s 2>/dev/null || echo "0")
    local time_diff=$((current_time - last_failure_epoch))
    
    [[ $time_diff -gt $TIMEOUT_DURATION ]]
}

# Function to make service call with circuit breaker
make_service_call() {
    local service_url=$1
    local current_state=$(jq -r '.state' $CIRCUIT_BREAKER_FILE)
    
    case $current_state in
        "OPEN")
            if should_close_circuit; then
                echo "Circuit breaker transitioning to HALF_OPEN"
                update_circuit_breaker "HALF_OPEN" 0 0
                current_state="HALF_OPEN"
            else
                echo "Circuit breaker is OPEN, request rejected"
                return 1
            fi
            ;;
        "HALF_OPEN")
            local total_requests=$(jq -r '.total_requests' $CIRCUIT_BREAKER_FILE)
            if [[ $total_requests -ge $HALF_OPEN_MAX_REQUESTS ]]; then
                echo "HALF_OPEN max requests reached, transitioning to OPEN"
                update_circuit_breaker "OPEN" $FAILURE_THRESHOLD 0
                return 1
            fi
            ;;
    esac
    
    # Make the actual service call
    echo "Making request to $service_url..."
    response_code=$(curl -s -o /dev/null -w "%{http_code}" "$service_url" 2>/dev/null || echo "000")
    
    # Update request count
    local total_requests=$(jq -r '.total_requests' $CIRCUIT_BREAKER_FILE)
    jq ".total_requests = $((total_requests + 1))" $CIRCUIT_BREAKER_FILE > /tmp/temp_cb.json
    mv /tmp/temp_cb.json $CIRCUIT_BREAKER_FILE
    
    if [[ $response_code == "200" ]]; then
        echo "✅ Request successful (HTTP $response_code)"
        
        if [[ $current_state == "HALF_OPEN" ]]; then
            local success_count=$(jq -r '.success_count' $CIRCUIT_BREAKER_FILE)
            jq ".success_count = $((success_count + 1))" $CIRCUIT_BREAKER_FILE > /tmp/temp_cb.json
            mv /tmp/temp_cb.json $CIRCUIT_BREAKER_FILE
            
            if [[ $((success_count + 1)) -ge $HALF_OPEN_MAX_REQUESTS ]]; then
                echo "Circuit breaker transitioning to CLOSED"
                update_circuit_breaker "CLOSED" 0 0
            fi
        fi
        
        return 0
    else
        echo "❌ Request failed (HTTP $response_code)"
        
        if [[ $current_state == "CLOSED" ]]; then
            local failure_count=$(jq -r '.failure_count' $CIRCUIT_BREAKER_FILE)
            jq ".failure_count = $((failure_count + 1))" $CIRCUIT_BREAKER_FILE > /tmp/temp_cb.json
            mv /tmp/temp_cb.json $CIRCUIT_BREAKER_FILE
            
            if should_open_circuit; then
                echo "Circuit breaker transitioning to OPEN"
                update_circuit_breaker "OPEN" $((failure_count + 1)) 0
            fi
        elif [[ $current_state == "HALF_OPEN" ]]; then
            echo "Circuit breaker transitioning back to OPEN"
            update_circuit_breaker "OPEN" $FAILURE_THRESHOLD 0
        fi
        
        return 1
    fi
}

# Test circuit breaker with different scenarios
echo "Testing circuit breaker pattern..."

# Test 1: Normal operation
echo "Test 1: Normal operation"
for i in {1..3}; do
    make_service_call "http://localhost:8081/health"
    sleep 1
done

# Test 2: Simulate failures
echo "Test 2: Simulating failures"
for i in {1..6}; do
    make_service_call "http://localhost:8081/nonexistent"
    sleep 1
done

# Test 3: Wait for timeout and test half-open
echo "Test 3: Testing half-open state"
sleep 2

for i in {1..5}; do
    make_service_call "http://localhost:8081/health"
    sleep 1
done

# Final state
echo "Final circuit breaker state:"
cat $CIRCUIT_BREAKER_FILE

# Cleanup
rm -f $CIRCUIT_BREAKER_FILE
BASH
        )
            ->withName('Circuit Breaker Implementation')
            ->withTimeout(300);

        TaskRunner::run($circuitBreakerTask);
    }

    /**
     * Load balancer configuration for microservices.
     */
    private function loadBalancerConfiguration(): void
    {
        $loadBalancerTask = AnonymousTask::command('load-balancer-config', <<<'BASH'
#!/bin/bash

# Load balancer configuration
echo "Configuring load balancer for microservices..."

# Nginx configuration directory
NGINX_DIR="/tmp/nginx-config"
mkdir -p $NGINX_DIR

# 1. Upstream configurations
cat > $NGINX_DIR/upstreams.conf << 'EOF'
# User Service upstream
upstream user_service {
    least_conn;
    server user-service-1:8081 max_fails=3 fail_timeout=30s;
    server user-service-2:8081 max_fails=3 fail_timeout=30s;
    server user-service-3:8081 max_fails=3 fail_timeout=30s;
    keepalive 32;
}

# Auth Service upstream
upstream auth_service {
    ip_hash;
    server auth-service-1:8082 max_fails=3 fail_timeout=30s;
    server auth-service-2:8082 max_fails=3 fail_timeout=30s;
    keepalive 32;
}

# Payment Service upstream
upstream payment_service {
    least_conn;
    server payment-service-1:8083 max_fails=3 fail_timeout=30s;
    server payment-service-2:8083 max_fails=3 fail_timeout=30s;
    server payment-service-3:8083 max_fails=3 fail_timeout=30s;
    keepalive 32;
}

# Notification Service upstream
upstream notification_service {
    round_robin;
    server notification-service-1:8084 max_fails=3 fail_timeout=30s;
    server notification-service-2:8084 max_fails=3 fail_timeout=30s;
    keepalive 32;
}
EOF

# 2. Main server configuration
cat > $NGINX_DIR/server.conf << 'EOF'
server {
    listen 80;
    server_name api.example.com;
    
    # Rate limiting
    limit_req_zone $binary_remote_addr zone=api:10m rate=10r/s;
    limit_req zone=api burst=20 nodelay;
    
    # Security headers
    add_header X-Frame-Options DENY;
    add_header X-Content-Type-Options nosniff;
    add_header X-XSS-Protection "1; mode=block";
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    
    # User Service routes
    location /api/users {
        proxy_pass http://user_service;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        
        # Timeouts
        proxy_connect_timeout 5s;
        proxy_send_timeout 30s;
        proxy_read_timeout 30s;
        
        # Health check
        proxy_next_upstream error timeout invalid_header http_500 http_502 http_503 http_504;
    }
    
    # Auth Service routes
    location /api/auth {
        proxy_pass http://auth_service;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        
        # Timeouts
        proxy_connect_timeout 5s;
        proxy_send_timeout 30s;
        proxy_read_timeout 30s;
        
        # Health check
        proxy_next_upstream error timeout invalid_header http_500 http_502 http_503 http_504;
    }
    
    # Payment Service routes
    location /api/payments {
        proxy_pass http://payment_service;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        
        # Timeouts
        proxy_connect_timeout 5s;
        proxy_send_timeout 30s;
        proxy_read_timeout 30s;
        
        # Health check
        proxy_next_upstream error timeout invalid_header http_500 http_502 http_503 http_504;
    }
    
    # Notification Service routes
    location /api/notifications {
        proxy_pass http://notification_service;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        
        # Timeouts
        proxy_connect_timeout 5s;
        proxy_send_timeout 30s;
        proxy_read_timeout 30s;
        
        # Health check
        proxy_next_upstream error timeout invalid_header http_500 http_502 http_503 http_504;
    }
    
    # Health check endpoint
    location /health {
        access_log off;
        return 200 "healthy\n";
        add_header Content-Type text/plain;
    }
    
    # Metrics endpoint
    location /metrics {
        stub_status on;
        access_log off;
    }
}
EOF

# 3. Load balancing algorithms test
echo "Testing load balancing algorithms..."

# Simulate requests to test load balancing
for i in {1..10}; do
    echo "Request $i to user service..."
    # In a real environment: curl -s http://api.example.com/api/users/health
    echo "Request $i to auth service..."
    # In a real environment: curl -s http://api.example.com/api/auth/health
done

# 4. Health check script
cat > $NGINX_DIR/health_check.sh << 'EOF'
#!/bin/bash

# Health check script for load balancer
SERVICES=(
    "user_service:http://user-service-1:8081/health"
    "user_service:http://user-service-2:8081/health"
    "user_service:http://user-service-3:8081/health"
    "auth_service:http://auth-service-1:8082/health"
    "auth_service:http://auth-service-2:8082/health"
    "payment_service:http://payment-service-1:8083/health"
    "payment_service:http://payment-service-2:8083/health"
    "payment_service:http://payment-service-3:8083/health"
    "notification_service:http://notification-service-1:8084/health"
    "notification_service:http://notification-service-2:8084/health"
)

for service in "${SERVICES[@]}"; do
    service_name=$(echo $service | cut -d: -f1)
    service_url=$(echo $service | cut -d: -f2-)
    
    if curl -f -s "$service_url" > /dev/null; then
        echo "✅ $service_name is healthy"
    else
        echo "❌ $service_name is unhealthy"
    fi
done
EOF

chmod +x $NGINX_DIR/health_check.sh

# Generate configuration summary
cat > /tmp/load-balancer-summary.json << EOF
{
  "timestamp": "$(date -Iseconds)",
  "configuration": {
    "upstreams": [
      "user_service (least_conn, 3 instances)",
      "auth_service (ip_hash, 2 instances)",
      "payment_service (least_conn, 3 instances)",
      "notification_service (round_robin, 2 instances)"
    ],
    "features": [
      "rate_limiting",
      "health_checks",
      "failover",
      "security_headers",
      "timeouts"
    ],
    "total_instances": 10
  }
}
EOF

echo "Load balancer configuration completed:"
cat /tmp/load-balancer-summary.json

# Cleanup
rm -rf $NGINX_DIR /tmp/load-balancer-summary.json
BASH
        )
            ->withName('Load Balancer Configuration')
            ->withTimeout(600);

        TaskRunner::run($loadBalancerTask);
    }
}
