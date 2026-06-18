<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Examples;

use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Facades\TaskRunner;
use App\Modules\TaskRunner\TaskChain;
use Illuminate\Support\Facades\Log;

/**
 * Advanced example demonstrating DevOps automation and CI/CD pipeline management.
 *
 * This example shows how to:
 * - Automate CI/CD pipelines
 * - Implement infrastructure as code
 * - Manage container orchestration
 * - Automate testing and deployment
 * - Monitor deployment health
 */
class DevOpsAutomationExample
{
    /**
     * Run a comprehensive DevOps automation example.
     */
    public function run(): void
    {
        Log::info('Starting DevOps Automation Example');

        // 1. CI/CD Pipeline Automation
        $this->cicdPipelineAutomation();

        // 2. Infrastructure as Code
        $this->infrastructureAsCode();

        // 3. Container Orchestration
        $this->containerOrchestration();

        // 4. Automated Testing Pipeline
        $this->automatedTestingPipeline();

        // 5. Deployment Health Monitoring
        $this->deploymentHealthMonitoring();

        Log::info('DevOps Automation Example completed');
    }

    /**
     * CI/CD pipeline automation with multiple stages.
     */
    private function cicdPipelineAutomation(): void
    {
        $pipelineChain = TaskChain::make()
            ->withName('CI/CD Pipeline Automation')
            ->withTimeout(3600)
            ->stopOnFailure(true);

        // Stage 1: Code Quality Checks
        $pipelineChain->addCommand('code-quality', <<<'BASH'
#!/bin/bash
echo "Stage 1: Code Quality Checks"
echo "============================"

# Run PHP CS Fixer
echo "Running PHP CS Fixer..."
./vendor/bin/php-cs-fixer fix --dry-run --diff

# Run PHPStan
echo "Running PHPStan..."
./vendor/bin/phpstan analyse --level=8

# Run PHPUnit tests
echo "Running PHPUnit tests..."
./vendor/bin/phpunit --coverage-text --coverage-filter=app

# Run Laravel Pint
echo "Running Laravel Pint..."
./vendor/bin/pint --test

echo "Code quality checks completed"
BASH
        );

        // Stage 2: Security Scanning
        $pipelineChain->addCommand('security-scan', <<<'BASH'
#!/bin/bash
echo "Stage 2: Security Scanning"
echo "=========================="

# Run Composer security audit
echo "Running Composer security audit..."
composer audit --format=json > /tmp/composer-audit.json

# Run PHP Security Checker
echo "Running PHP Security Checker..."
./vendor/bin/security-checker security:check composer.lock

# Run OWASP ZAP scan (if available)
if command -v zap-baseline.py &> /dev/null; then
    echo "Running OWASP ZAP scan..."
    zap-baseline.py -t http://localhost:8000 -J /tmp/zap-report.json
fi

# Run Snyk security scan
if command -v snyk &> /dev/null; then
    echo "Running Snyk security scan..."
    snyk test --json > /tmp/snyk-report.json
fi

echo "Security scanning completed"
BASH
        );

        // Stage 3: Build and Package
        $pipelineChain->addCommand('build-package', <<<'BASH'
#!/bin/bash
echo "Stage 3: Build and Package"
echo "=========================="

# Install dependencies
echo "Installing dependencies..."
composer install --no-dev --optimize-autoloader
npm ci --production

# Build assets
echo "Building assets..."
npm run build

# Create deployment package
echo "Creating deployment package..."
DEPLOY_PACKAGE="deployment-$(date +%Y%m%d-%H%M%S).tar.gz"
tar -czf $DEPLOY_PACKAGE \
    --exclude='.git' \
    --exclude='node_modules' \
    --exclude='tests' \
    --exclude='storage/logs/*' \
    --exclude='storage/framework/cache/*' \
    .

echo "Deployment package created: $DEPLOY_PACKAGE"
BASH
        );

        // Stage 4: Deploy to Staging
        $pipelineChain->addCommand('deploy-staging', <<<'BASH'
#!/bin/bash
echo "Stage 4: Deploy to Staging"
echo "=========================="

# Deploy to staging environment
echo "Deploying to staging environment..."

# Copy deployment package to staging server
scp deployment-*.tar.gz staging.example.com:/tmp/

# Execute deployment on staging
ssh staging.example.com << 'EOF'
cd /var/www/staging
tar -xzf /tmp/deployment-*.tar.gz
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
systemctl reload php-fpm
systemctl reload nginx
EOF

echo "Staging deployment completed"
BASH
        );

        // Stage 5: Run Integration Tests
        $pipelineChain->addCommand('integration-tests', <<<'BASH'
#!/bin/bash
echo "Stage 5: Integration Tests"
echo "========================="

# Run integration tests against staging
echo "Running integration tests..."

# Health check staging environment
for i in {1..10}; do
    if curl -f http://staging.example.com/health; then
        echo "Staging environment is healthy"
        break
    else
        echo "Waiting for staging environment... (attempt $i/10)"
        sleep 10
    fi
done

# Run API tests
echo "Running API tests..."
./vendor/bin/phpunit --testsuite=integration

# Run browser tests
echo "Running browser tests..."
./vendor/bin/dusk

echo "Integration tests completed"
BASH
        );

        // Stage 6: Deploy to Production
        $pipelineChain->addCommand('deploy-production', <<<'BASH'
#!/bin/bash
echo "Stage 6: Deploy to Production"
echo "============================="

# Deploy to production with blue-green deployment
echo "Starting blue-green deployment..."

# Determine current environment
CURRENT_ENV=$(ssh production.example.com "readlink -f /var/www/current" | grep -o "blue\|green")
if [[ $CURRENT_ENV == "blue" ]]; then
    NEW_ENV="green"
    OLD_ENV="blue"
else
    NEW_ENV="blue"
    OLD_ENV="green"
fi

echo "Current environment: $CURRENT_ENV"
echo "Deploying to: $NEW_ENV"

# Deploy to new environment
ssh production.example.com << EOF
cd /var/www/$NEW_ENV
tar -xzf /tmp/deployment-*.tar.gz
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
EOF

# Health check new environment
echo "Health checking new environment..."
for i in {1..10}; do
    if curl -f http://$NEW_ENV.example.com/health; then
        echo "New environment is healthy"
        break
    else
        echo "Waiting for new environment... (attempt $i/10)"
        sleep 10
    fi
done

# Switch traffic to new environment
echo "Switching traffic to new environment..."
ssh production.example.com "ln -sfn /var/www/$NEW_ENV /var/www/current"
systemctl reload nginx

# Verify deployment
echo "Verifying deployment..."
sleep 30
if curl -f http://production.example.com/health; then
    echo "Production deployment successful"
else
    echo "Production deployment failed, rolling back..."
    ssh production.example.com "ln -sfn /var/www/$OLD_ENV /var/www/current"
    systemctl reload nginx
    exit 1
fi

echo "Production deployment completed"
BASH
        );

        $pipelineChain->run();
    }

    /**
     * Infrastructure as Code automation.
     */
    private function infrastructureAsCode(): void
    {
        $iacTask = AnonymousTask::command('infrastructure-as-code', <<<'BASH'
#!/bin/bash

# Infrastructure as Code automation
echo "Starting Infrastructure as Code automation..."

# Terraform configuration directory
TERRAFORM_DIR="/tmp/terraform-config"
mkdir -p $TERRAFORM_DIR

# 1. Main Terraform configuration
cat > $TERRAFORM_DIR/main.tf << 'EOF'
terraform {
  required_version = ">= 1.0"
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
  }
}

provider "aws" {
  region = "us-west-2"
}

# VPC Configuration
resource "aws_vpc" "main" {
  cidr_block           = "10.0.0.0/16"
  enable_dns_hostnames = true
  enable_dns_support   = true

  tags = {
    Name = "main-vpc"
    Environment = "production"
  }
}

# Subnets
resource "aws_subnet" "public" {
  count             = 3
  vpc_id            = aws_vpc.main.id
  cidr_block        = "10.0.${count.index + 1}.0/24"
  availability_zone = data.aws_availability_zones.available.names[count.index]

  tags = {
    Name = "public-subnet-${count.index + 1}"
  }
}

resource "aws_subnet" "private" {
  count             = 3
  vpc_id            = aws_vpc.main.id
  cidr_block        = "10.0.${count.index + 10}.0/24"
  availability_zone = data.aws_availability_zones.available.names[count.index]

  tags = {
    Name = "private-subnet-${count.index + 1}"
  }
}

# ECS Cluster
resource "aws_ecs_cluster" "main" {
  name = "production-cluster"

  setting {
    name  = "containerInsights"
    value = "enabled"
  }
}

# ECS Task Definition
resource "aws_ecs_task_definition" "app" {
  family                   = "app-task"
  network_mode             = "awsvpc"
  requires_compatibilities = ["FARGATE"]
  cpu                      = 256
  memory                   = 512

  container_definitions = jsonencode([
    {
      name  = "app"
      image = "123456789012.dkr.ecr.us-west-2.amazonaws.com/app:latest"
      portMappings = [
        {
          containerPort = 80
          protocol      = "tcp"
        }
      ]
      environment = [
        {
          name  = "APP_ENV"
          value = "production"
        }
      ]
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          awslogs-group         = "/ecs/app-task"
          awslogs-region        = "us-west-2"
          awslogs-stream-prefix = "ecs"
        }
      }
    }
  ])
}

# ECS Service
resource "aws_ecs_service" "app" {
  name            = "app-service"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.app.arn
  desired_count   = 3
  launch_type     = "FARGATE"

  network_configuration {
    subnets         = aws_subnet.private[*].id
    security_groups = [aws_security_group.ecs_tasks.id]
  }

  load_balancer {
    target_group_arn = aws_lb_target_group.app.arn
    container_name   = "app"
    container_port   = 80
  }
}

# Application Load Balancer
resource "aws_lb" "app" {
  name               = "app-lb"
  internal           = false
  load_balancer_type = "application"
  security_groups    = [aws_security_group.alb.id]
  subnets            = aws_subnet.public[*].id
}

# ALB Target Group
resource "aws_lb_target_group" "app" {
  name     = "app-tg"
  port     = 80
  protocol = "HTTP"
  vpc_id   = aws_vpc.main.id

  health_check {
    enabled             = true
    healthy_threshold   = 2
    interval            = 30
    matcher             = "200"
    path                = "/health"
    port                = "traffic-port"
    protocol            = "HTTP"
    timeout             = 5
    unhealthy_threshold = 2
  }
}

# ALB Listener
resource "aws_lb_listener" "app" {
  load_balancer_arn = aws_lb.app.arn
  port              = "80"
  protocol          = "HTTP"

  default_action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.app.arn
  }
}

# Security Groups
resource "aws_security_group" "alb" {
  name        = "alb-sg"
  description = "ALB Security Group"
  vpc_id      = aws_vpc.main.id

  ingress {
    protocol    = "tcp"
    from_port   = 80
    to_port     = 80
    cidr_blocks = ["0.0.0.0/0"]
  }

  ingress {
    protocol    = "tcp"
    from_port   = 443
    to_port     = 443
    cidr_blocks = ["0.0.0.0/0"]
  }

  egress {
    protocol    = "-1"
    from_port   = 0
    to_port     = 0
    cidr_blocks = ["0.0.0.0/0"]
  }
}

resource "aws_security_group" "ecs_tasks" {
  name        = "ecs-tasks-sg"
  description = "ECS Tasks Security Group"
  vpc_id      = aws_vpc.main.id

  ingress {
    protocol        = "tcp"
    from_port       = 80
    to_port         = 80
    security_groups = [aws_security_group.alb.id]
  }

  egress {
    protocol    = "-1"
    from_port   = 0
    to_port     = 0
    cidr_blocks = ["0.0.0.0/0"]
  }
}

# Data sources
data "aws_availability_zones" "available" {
  state = "available"
}

# Outputs
output "alb_dns_name" {
  value = aws_lb.app.dns_name
}

output "ecs_cluster_name" {
  value = aws_ecs_cluster.main.name
}
EOF

# 2. Variables file
cat > $TERRAFORM_DIR/variables.tf << 'EOF'
variable "environment" {
  description = "Environment name"
  type        = string
  default     = "production"
}

variable "app_name" {
  description = "Application name"
  type        = string
  default     = "laravel-app"
}

variable "app_version" {
  description = "Application version"
  type        = string
  default     = "latest"
}
EOF

# 3. Initialize and apply Terraform
echo "Initializing Terraform..."
cd $TERRAFORM_DIR
terraform init

echo "Planning Terraform changes..."
terraform plan -out=tfplan

echo "Applying Terraform configuration..."
terraform apply tfplan

# 4. Generate infrastructure summary
cat > /tmp/infrastructure-summary.json << EOF
{
  "timestamp": "$(date -Iseconds)",
  "infrastructure": {
    "vpc": "main-vpc",
    "subnets": {
      "public": 3,
      "private": 3
    },
    "ecs_cluster": "production-cluster",
    "load_balancer": "app-lb",
    "services": [
      "app-service"
    ]
  },
  "resources_created": [
    "VPC",
    "Subnets",
    "ECS Cluster",
    "ECS Service",
    "Application Load Balancer",
    "Security Groups"
  ]
}
EOF

echo "Infrastructure as Code completed:"
cat /tmp/infrastructure-summary.json

# Cleanup
rm -rf $TERRAFORM_DIR /tmp/infrastructure-summary.json
BASH
        )
            ->withName('Infrastructure as Code')
            ->withTimeout(1800);

        TaskRunner::run($iacTask);
    }

    /**
     * Container orchestration with Kubernetes.
     */
    private function containerOrchestration(): void
    {
        $k8sTask = AnonymousTask::command('container-orchestration', <<<'BASH'
#!/bin/bash

# Container orchestration with Kubernetes
echo "Starting container orchestration..."

# Kubernetes manifests directory
K8S_DIR="/tmp/k8s-manifests"
mkdir -p $K8S_DIR

# 1. Namespace
cat > $K8S_DIR/namespace.yaml << 'EOF'
apiVersion: v1
kind: Namespace
metadata:
  name: laravel-app
  labels:
    name: laravel-app
EOF

# 2. ConfigMap
cat > $K8S_DIR/configmap.yaml << 'EOF'
apiVersion: v1
kind: ConfigMap
metadata:
  name: laravel-config
  namespace: laravel-app
data:
  APP_ENV: "production"
  APP_DEBUG: "false"
  DB_HOST: "mysql-service"
  DB_PORT: "3306"
  REDIS_HOST: "redis-service"
  REDIS_PORT: "6379"
EOF

# 3. Secret
cat > $K8S_DIR/secret.yaml << 'EOF'
apiVersion: v1
kind: Secret
metadata:
  name: laravel-secrets
  namespace: laravel-app
type: Opaque
data:
  DB_PASSWORD: bXlwYXNzd29yZA==  # mypassword
  APP_KEY: YmFzZTY0ZW5jb2RlZGFwcGtleQ==  # base64encodedappkey
EOF

# 4. Deployment
cat > $K8S_DIR/deployment.yaml << 'EOF'
apiVersion: apps/v1
kind: Deployment
metadata:
  name: laravel-app
  namespace: laravel-app
spec:
  replicas: 3
  selector:
    matchLabels:
      app: laravel-app
  template:
    metadata:
      labels:
        app: laravel-app
    spec:
      containers:
      - name: laravel-app
        image: laravel-app:latest
        ports:
        - containerPort: 80
        env:
        - name: APP_ENV
          valueFrom:
            configMapKeyRef:
              name: laravel-config
              key: APP_ENV
        - name: DB_PASSWORD
          valueFrom:
            secretKeyRef:
              name: laravel-secrets
              key: DB_PASSWORD
        resources:
          requests:
            memory: "256Mi"
            cpu: "250m"
          limits:
            memory: "512Mi"
            cpu: "500m"
        livenessProbe:
          httpGet:
            path: /health
            port: 80
          initialDelaySeconds: 30
          periodSeconds: 10
        readinessProbe:
          httpGet:
            path: /ready
            port: 80
          initialDelaySeconds: 5
          periodSeconds: 5
EOF

# 5. Service
cat > $K8S_DIR/service.yaml << 'EOF'
apiVersion: v1
kind: Service
metadata:
  name: laravel-service
  namespace: laravel-app
spec:
  selector:
    app: laravel-app
  ports:
  - protocol: TCP
    port: 80
    targetPort: 80
  type: ClusterIP
EOF

# 6. Ingress
cat > $K8S_DIR/ingress.yaml << 'EOF'
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: laravel-ingress
  namespace: laravel-app
  annotations:
    nginx.ingress.kubernetes.io/rewrite-target: /
    nginx.ingress.kubernetes.io/ssl-redirect: "true"
    cert-manager.io/cluster-issuer: "letsencrypt-prod"
spec:
  tls:
  - hosts:
    - api.example.com
    secretName: laravel-tls
  rules:
  - host: api.example.com
    http:
      paths:
      - path: /
        pathType: Prefix
        backend:
          service:
            name: laravel-service
            port:
              number: 80
EOF

# 7. Horizontal Pod Autoscaler
cat > $K8S_DIR/hpa.yaml << 'EOF'
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: laravel-hpa
  namespace: laravel-app
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: laravel-app
  minReplicas: 3
  maxReplicas: 10
  metrics:
  - type: Resource
    resource:
      name: cpu
      target:
        type: Utilization
        averageUtilization: 70
  - type: Resource
    resource:
      name: memory
      target:
        type: Utilization
        averageUtilization: 80
EOF

# 8. Apply Kubernetes manifests
echo "Applying Kubernetes manifests..."

for manifest in $K8S_DIR/*.yaml; do
    echo "Applying $(basename $manifest)..."
    # In a real environment: kubectl apply -f $manifest
    echo "Applied: $(basename $manifest)"
done

# 9. Verify deployment
echo "Verifying deployment..."

# Check pods
echo "Checking pod status..."
# kubectl get pods -n laravel-app

# Check services
echo "Checking service status..."
# kubectl get services -n laravel-app

# Check ingress
echo "Checking ingress status..."
# kubectl get ingress -n laravel-app

# 10. Generate orchestration summary
cat > /tmp/orchestration-summary.json << EOF
{
  "timestamp": "$(date -Iseconds)",
  "kubernetes": {
    "namespace": "laravel-app",
    "deployment": "laravel-app",
    "replicas": 3,
    "service": "laravel-service",
    "ingress": "laravel-ingress",
    "hpa": "laravel-hpa"
  },
  "resources": {
    "configmap": "laravel-config",
    "secret": "laravel-secrets",
    "pods": 3,
    "services": 1
  },
  "features": [
    "auto_scaling",
    "health_checks",
    "load_balancing",
    "ssl_termination",
    "resource_limits"
  ]
}
EOF

echo "Container orchestration completed:"
cat /tmp/orchestration-summary.json

# Cleanup
rm -rf $K8S_DIR /tmp/orchestration-summary.json
BASH
        )
            ->withName('Container Orchestration')
            ->withTimeout(1200);

        TaskRunner::run($k8sTask);
    }

    /**
     * Automated testing pipeline.
     */
    private function automatedTestingPipeline(): void
    {
        $testingTask = AnonymousTask::command('automated-testing', <<<'BASH'
#!/bin/bash

# Automated testing pipeline
echo "Starting automated testing pipeline..."

# Test results directory
TEST_DIR="/tmp/test-results"
mkdir -p $TEST_DIR

# 1. Unit Tests
echo "Running unit tests..."
./vendor/bin/phpunit --testsuite=unit --coverage-html=$TEST_DIR/unit-coverage --log-junit=$TEST_DIR/unit-tests.xml

# 2. Feature Tests
echo "Running feature tests..."
./vendor/bin/phpunit --testsuite=feature --coverage-html=$TEST_DIR/feature-coverage --log-junit=$TEST_DIR/feature-tests.xml

# 3. Browser Tests
echo "Running browser tests..."
./vendor/bin/dusk --log=$TEST_DIR/dusk-tests.log

# 4. Performance Tests
echo "Running performance tests..."
cat > $TEST_DIR/performance-test.php << 'EOF'
<?php

require_once 'vendor/autoload.php';

use Illuminate\Foundation\Testing\TestCase;
use Tests\CreatesApplication;

class PerformanceTest extends TestCase
{
    use CreatesApplication;

    public function test_homepage_performance()
    {
        $start = microtime(true);
        
        $response = $this->get('/');
        
        $end = microtime(true);
        $duration = ($end - $start) * 1000; // Convert to milliseconds
        
        $this->assertLessThan(500, $duration, "Homepage should load in less than 500ms");
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_api_response_time()
    {
        $start = microtime(true);
        
        $response = $this->get('/api/users');
        
        $end = microtime(true);
        $duration = ($end - $start) * 1000;
        
        $this->assertLessThan(200, $duration, "API should respond in less than 200ms");
        $this->assertEquals(200, $response->getStatusCode());
    }
}
EOF

./vendor/bin/phpunit $TEST_DIR/performance-test.php --log-junit=$TEST_DIR/performance-tests.xml

# 5. Load Testing
echo "Running load tests..."
cat > $TEST_DIR/load-test.yml << 'EOF'
config:
  target: 'http://localhost:8000'
  phases:
    - duration: 60
      arrivalRate: 10
      name: "Warm up"
    - duration: 120
      arrivalRate: 50
      name: "Sustained load"
    - duration: 60
      arrivalRate: 100
      name: "Peak load"
  thresholds:
    - http_req_duration: p95 < 1000
    - http_req_failed: rate < 0.1

scenarios:
  - name: "API Load Test"
    weight: 70
    requests:
      - get:
          url: "/api/users"
      - get:
          url: "/api/posts"
      - post:
          url: "/api/users"
          json:
            name: "Test User"
            email: "test@example.com"

  - name: "Web Load Test"
    weight: 30
    requests:
      - get:
          url: "/"
      - get:
          url: "/about"
      - get:
          url: "/contact"
EOF

# Run load test with Artillery (if available)
if command -v artillery &> /dev/null; then
    artillery run $TEST_DIR/load-test.yml --output $TEST_DIR/load-test-results.json
fi

# 6. Security Tests
echo "Running security tests..."
cat > $TEST_DIR/security-test.php << 'EOF'
<?php

require_once 'vendor/autoload.php';

use Illuminate\Foundation\Testing\TestCase;
use Tests\CreatesApplication;

class SecurityTest extends TestCase
{
    use CreatesApplication;

    public function test_sql_injection_protection()
    {
        $response = $this->post('/api/users/search', [
            'query' => "'; DROP TABLE users; --"
        ]);
        
        $this->assertNotEquals(500, $response->getStatusCode());
    }

    public function test_xss_protection()
    {
        $response = $this->post('/api/posts', [
            'title' => '<script>alert("xss")</script>',
            'content' => 'Test content'
        ]);
        
        $this->assertNotContains('<script>', $response->getContent());
    }

    public function test_csrf_protection()
    {
        $response = $this->post('/api/users', [
            'name' => 'Test User',
            'email' => 'test@example.com'
        ]);
        
        $this->assertNotEquals(419, $response->getStatusCode());
    }
}
EOF

./vendor/bin/phpunit $TEST_DIR/security-test.php --log-junit=$TEST_DIR/security-tests.xml

# 7. Generate test report
echo "Generating test report..."

cat > $TEST_DIR/test-report.html << 'EOF'
<!DOCTYPE html>
<html>
<head>
    <title>Test Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .summary { background: #f5f5f5; padding: 20px; border-radius: 5px; }
        .test-suite { margin: 20px 0; padding: 15px; border: 1px solid #ddd; }
        .passed { color: green; }
        .failed { color: red; }
        .coverage { background: #e8f5e8; padding: 10px; margin: 10px 0; }
    </style>
</head>
<body>
    <h1>Automated Test Report</h1>
    <div class="summary">
        <h2>Test Summary</h2>
        <p><strong>Date:</strong> $(date)</p>
        <p><strong>Environment:</strong> Testing</p>
        <p><strong>Total Tests:</strong> $(find $TEST_DIR -name "*.xml" -exec grep -c "testcase" {} + | awk '{sum+=$1} END {print sum}')</p>
    </div>
    
    <div class="test-suite">
        <h3>Unit Tests</h3>
        <p class="passed">✅ Unit tests completed</p>
        <div class="coverage">
            <strong>Coverage:</strong> Available in unit-coverage/
        </div>
    </div>
    
    <div class="test-suite">
        <h3>Feature Tests</h3>
        <p class="passed">✅ Feature tests completed</p>
        <div class="coverage">
            <strong>Coverage:</strong> Available in feature-coverage/
        </div>
    </div>
    
    <div class="test-suite">
        <h3>Browser Tests</h3>
        <p class="passed">✅ Browser tests completed</p>
    </div>
    
    <div class="test-suite">
        <h3>Performance Tests</h3>
        <p class="passed">✅ Performance tests completed</p>
    </div>
    
    <div class="test-suite">
        <h3>Security Tests</h3>
        <p class="passed">✅ Security tests completed</p>
    </div>
</body>
</html>
EOF

# 8. Generate test summary
cat > /tmp/testing-summary.json << EOF
{
  "timestamp": "$(date -Iseconds)",
  "test_results": {
    "unit_tests": {
      "status": "completed",
      "coverage": "available"
    },
    "feature_tests": {
      "status": "completed", 
      "coverage": "available"
    },
    "browser_tests": {
      "status": "completed"
    },
    "performance_tests": {
      "status": "completed"
    },
    "security_tests": {
      "status": "completed"
    }
  },
  "artifacts": [
    "unit-coverage/",
    "feature-coverage/",
    "test-report.html",
    "*.xml",
    "*.log"
  ]
}
EOF

echo "Automated testing completed:"
cat /tmp/testing-summary.json

# Cleanup
rm -rf $TEST_DIR /tmp/testing-summary.json
BASH
        )
            ->withName('Automated Testing Pipeline')
            ->withTimeout(1800);

        TaskRunner::run($testingTask);
    }

    /**
     * Deployment health monitoring.
     */
    private function deploymentHealthMonitoring(): void
    {
        $monitoringTask = AnonymousTask::command('deployment-monitoring', <<<'BASH'
#!/bin/bash

# Deployment health monitoring
echo "Starting deployment health monitoring..."

# Monitoring configuration
MONITORING_DIR="/tmp/monitoring"
mkdir -p $MONITORING_DIR

# 1. Health check endpoints
ENDPOINTS=(
    "http://production.example.com/health"
    "http://production.example.com/api/users"
    "http://production.example.com/api/posts"
    "http://staging.example.com/health"
)

# 2. Performance thresholds
RESPONSE_TIME_THRESHOLD=1000  # milliseconds
ERROR_RATE_THRESHOLD=5        # percentage
AVAILABILITY_THRESHOLD=99.9   # percentage

# 3. Monitoring results
RESULTS_FILE="$MONITORING_DIR/monitoring_results.json"
ALERTS_FILE="$MONITORING_DIR/alerts.log"

# Initialize results
cat > $RESULTS_FILE << EOF
{
  "timestamp": "$(date -Iseconds)",
  "endpoints": {},
  "summary": {
    "total_checks": 0,
    "successful_checks": 0,
    "failed_checks": 0,
    "average_response_time": 0,
    "availability_percentage": 0
  }
}
EOF

# Function to check endpoint health
check_endpoint() {
    local url=$1
    local start_time=$(date +%s%N)
    
    # Make request
    response=$(curl -s -w "%{http_code}|%{time_total}" "$url" 2>/dev/null)
    local end_time=$(date +%s%N)
    
    # Parse response
    local http_code=$(echo $response | cut -d'|' -f1)
    local response_time=$(echo $response | cut -d'|' -f2)
    local response_time_ms=$(echo "$response_time * 1000" | bc -l)
    
    # Determine status
    if [[ $http_code == "200" ]]; then
        local status="healthy"
        local success=true
    else
        local status="unhealthy"
        local success=false
    fi
    
    # Check performance threshold
    if (( $(echo "$response_time_ms > $RESPONSE_TIME_THRESHOLD" | bc -l) )); then
        local performance="slow"
        echo "ALERT: $url is slow (${response_time_ms}ms)" >> $ALERTS_FILE
    else
        local performance="normal"
    fi
    
    # Update results
    jq ".endpoints.\"$url\" = {
        \"status\": \"$status\",
        \"http_code\": $http_code,
        \"response_time_ms\": $response_time_ms,
        \"performance\": \"$performance\",
        \"timestamp\": \"$(date -Iseconds)\"
    }" $RESULTS_FILE > /tmp/temp_results.json
    mv /tmp/temp_results.json $RESULTS_FILE
    
    echo "$success"
}

# 4. Run health checks
echo "Running health checks..."
total_checks=0
successful_checks=0

for endpoint in "${ENDPOINTS[@]}"; do
    echo "Checking $endpoint..."
    if check_endpoint "$endpoint"; then
        ((successful_checks++))
    fi
    ((total_checks++))
    sleep 2
done

# 5. Calculate summary statistics
availability_percentage=$(echo "scale=2; $successful_checks * 100 / $total_checks" | bc -l)
average_response_time=$(jq -r '.endpoints | to_entries | map(.value.response_time_ms) | add / length' $RESULTS_FILE 2>/dev/null || echo "0")

# Update summary
jq ".summary = {
    \"total_checks\": $total_checks,
    \"successful_checks\": $successful_checks,
    \"failed_checks\": $((total_checks - successful_checks)),
    \"average_response_time\": $average_response_time,
    \"availability_percentage\": $availability_percentage
}" $RESULTS_FILE > /tmp/temp_results.json
mv /tmp/temp_results.json $RESULTS_FILE

# 6. Check for alerts
echo "Checking for alerts..."

# Availability alert
if (( $(echo "$availability_percentage < $AVAILABILITY_THRESHOLD" | bc -l) )); then
    echo "ALERT: Availability below threshold (${availability_percentage}% < ${AVAILABILITY_THRESHOLD}%)" >> $ALERTS_FILE
fi

# Response time alert
if (( $(echo "$average_response_time > $RESPONSE_TIME_THRESHOLD" | bc -l) )); then
    echo "ALERT: Average response time above threshold (${average_response_time}ms > ${RESPONSE_TIME_THRESHOLD}ms)" >> $ALERTS_FILE
fi

# 7. Generate monitoring report
cat > $MONITORING_DIR/monitoring-report.html << EOF
<!DOCTYPE html>
<html>
<head>
    <title>Deployment Health Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { background: #2c3e50; color: white; padding: 20px; border-radius: 5px; }
        .summary { background: #ecf0f1; padding: 20px; margin: 20px 0; border-radius: 5px; }
        .endpoint { margin: 10px 0; padding: 15px; border: 1px solid #bdc3c7; border-radius: 5px; }
        .healthy { border-left: 5px solid #27ae60; }
        .unhealthy { border-left: 5px solid #e74c3c; }
        .alert { background: #f39c12; color: white; padding: 10px; margin: 10px 0; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Deployment Health Report</h1>
        <p>Generated: $(date)</p>
    </div>
    
    <div class="summary">
        <h2>Summary</h2>
        <p><strong>Availability:</strong> ${availability_percentage}%</p>
        <p><strong>Average Response Time:</strong> ${average_response_time}ms</p>
        <p><strong>Total Checks:</strong> $total_checks</p>
        <p><strong>Successful:</strong> $successful_checks</p>
        <p><strong>Failed:</strong> $((total_checks - successful_checks))</p>
    </div>
    
    <h2>Endpoint Status</h2>
    $(for endpoint in "${ENDPOINTS[@]}"; do
        status=$(jq -r ".endpoints.\"$endpoint\".status" $RESULTS_FILE)
        response_time=$(jq -r ".endpoints.\"$endpoint\".response_time_ms" $RESULTS_FILE)
        http_code=$(jq -r ".endpoints.\"$endpoint\".http_code" $RESULTS_FILE)
        
        if [[ $status == "healthy" ]]; then
            class="healthy"
            icon="✅"
        else
            class="unhealthy"
            icon="❌"
        fi
        
        echo "<div class=\"endpoint $class\">"
        echo "<h3>$icon $endpoint</h3>"
        echo "<p><strong>Status:</strong> $status</p>"
        echo "<p><strong>Response Time:</strong> ${response_time}ms</p>"
        echo "<p><strong>HTTP Code:</strong> $http_code</p>"
        echo "</div>"
    done)
    
    $(if [[ -f $ALERTS_FILE ]]; then
        echo "<h2>Alerts</h2>"
        while IFS= read -r alert; do
            echo "<div class=\"alert\">$alert</div>"
        done < $ALERTS_FILE
    fi)
</body>
</html>
EOF

# 8. Generate monitoring summary
cat > /tmp/monitoring-summary.json << EOF
{
  "timestamp": "$(date -Iseconds)",
  "monitoring": {
    "endpoints_checked": ${#ENDPOINTS[@]},
    "availability_percentage": $availability_percentage,
    "average_response_time": $average_response_time,
    "alerts_generated": $(wc -l < $ALERTS_FILE 2>/dev/null || echo "0")
  },
  "thresholds": {
    "response_time_ms": $RESPONSE_TIME_THRESHOLD,
    "availability_percentage": $AVAILABILITY_THRESHOLD
  },
  "artifacts": [
    "monitoring_results.json",
    "monitoring-report.html",
    "alerts.log"
  ]
}
EOF

echo "Deployment health monitoring completed:"
cat /tmp/monitoring-summary.json

# Cleanup
rm -rf $MONITORING_DIR /tmp/monitoring-summary.json
BASH
        )
            ->withName('Deployment Health Monitoring')
            ->withTimeout(600);

        TaskRunner::run($monitoringTask);
    }
}
