<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Examples;

use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Facades\TaskRunner;
use App\Modules\TaskRunner\Services\AnalyticsService;
use App\Modules\TaskRunner\Services\MonitoringService;
use Illuminate\Support\Facades\Log;

/**
 * Advanced example demonstrating streaming analytics and real-time monitoring.
 *
 * This example shows how to:
 * - Set up real-time analytics collection
 * - Monitor system resources with streaming updates
 * - Create custom analytics dashboards
 * - Handle streaming data processing
 * - Implement real-time alerting
 */
class StreamingAnalyticsExample
{
    public function __construct(
        private readonly AnalyticsService $analyticsService,
        private readonly MonitoringService $monitoringService
    ) {}

    /**
     * Run a comprehensive streaming analytics example.
     */
    public function run(): void
    {
        Log::info('Starting Streaming Analytics Example');

        // 1. System Resource Monitoring with Streaming
        $this->systemResourceMonitoring();

        // 2. Real-time Log Analysis
        $this->realTimeLogAnalysis();

        // 3. Custom Analytics Dashboard
        $this->customAnalyticsDashboard();

        // 4. Streaming Data Processing Pipeline
        $this->streamingDataPipeline();

        // 5. Real-time Alerting System
        $this->realTimeAlerting();

        Log::info('Streaming Analytics Example completed');
    }

    /**
     * Monitor system resources with real-time streaming updates.
     */
    private function systemResourceMonitoring(): void
    {
        $monitoringTask = AnonymousTask::view('system-monitoring', 'task-runner::streaming-monitor', [
            'metrics' => ['cpu', 'memory', 'disk', 'network'],
            'interval' => 5,
            'duration' => 300,
            'thresholds' => [
                'cpu' => 80,
                'memory' => 85,
                'disk' => 90,
            ],
        ])
            ->withName('System Resource Monitoring')
            ->withTimeout(600);

        TaskRunner::run($monitoringTask);
    }

    /**
     * Real-time log analysis with streaming processing.
     */
    private function realTimeLogAnalysis(): void
    {
        $logAnalysisTask = AnonymousTask::command('log-analysis', <<<'BASH'
#!/bin/bash

# Real-time log analysis with streaming
LOG_FILES=("/var/log/nginx/access.log" "/var/log/nginx/error.log" "/var/log/php-fpm.log")
ANALYSIS_FILE="/tmp/log_analysis_$(date +%Y%m%d_%H%M%S).json"

echo "Starting real-time log analysis..."

# Initialize analysis structure
cat > $ANALYSIS_FILE << 'EOF'
{
  "timestamp": "$(date -Iseconds)",
  "analysis": {
    "requests_per_minute": 0,
    "error_rate": 0,
    "top_ips": [],
    "top_endpoints": [],
    "response_times": [],
    "status_codes": {}
  }
}
EOF

# Monitor logs in real-time
for log_file in "${LOG_FILES[@]}"; do
    if [[ -f "$log_file" ]]; then
        echo "Monitoring: $log_file"
        
        # Tail the log file and process in real-time
        tail -f "$log_file" | while read line; do
            # Process each log line
            if [[ $line =~ ([0-9]{1,3}\.){3}[0-9]{1,3} ]]; then
                ip=$(echo "$line" | grep -oE '([0-9]{1,3}\.){3}[0-9]{1,3}' | head -1)
                echo "IP: $ip" >> /tmp/ip_analysis.log
            fi
            
            if [[ $line =~ "GET|POST|PUT|DELETE" ]]; then
                method=$(echo "$line" | grep -oE "(GET|POST|PUT|DELETE)")
                endpoint=$(echo "$line" | grep -oE "/[a-zA-Z0-9/_\-\.]+" | head -1)
                echo "Request: $method $endpoint" >> /tmp/request_analysis.log
            fi
            
            if [[ $line =~ "HTTP/[0-9.]+ [0-9]{3}" ]]; then
                status=$(echo "$line" | grep -oE "HTTP/[0-9.]+ [0-9]{3}" | grep -oE "[0-9]{3}")
                echo "Status: $status" >> /tmp/status_analysis.log
            fi
        done &
    fi
done

# Wait for monitoring to complete
sleep 60

# Generate final analysis
echo "Generating analysis report..."

# Count requests per minute
requests_per_minute=$(wc -l < /tmp/request_analysis.log)

# Calculate error rate
total_requests=$(wc -l < /tmp/status_analysis.log)
error_requests=$(grep -E "4[0-9]{2}|5[0-9]{2}" /tmp/status_analysis.log | wc -l)
error_rate=$(echo "scale=2; $error_requests * 100 / $total_requests" | bc -l 2>/dev/null || echo "0")

# Get top IPs
top_ips=$(sort /tmp/ip_analysis.log | uniq -c | sort -nr | head -5 | awk '{print $2": "$1}')

# Get top endpoints
top_endpoints=$(sort /tmp/request_analysis.log | uniq -c | sort -nr | head -5 | awk '{print $3": "$1}')

# Update analysis file
cat > $ANALYSIS_FILE << EOF
{
  "timestamp": "$(date -Iseconds)",
  "analysis": {
    "requests_per_minute": $requests_per_minute,
    "error_rate": $error_rate,
    "top_ips": ["$top_ips"],
    "top_endpoints": ["$top_endpoints"],
    "total_requests": $total_requests,
    "error_requests": $error_requests
  }
}
EOF

echo "Analysis complete: $ANALYSIS_FILE"
cat $ANALYSIS_FILE

# Cleanup
rm -f /tmp/ip_analysis.log /tmp/request_analysis.log /tmp/status_analysis.log
BASH
        )
            ->withName('Real-time Log Analysis')
            ->withTimeout(120);

        TaskRunner::run($logAnalysisTask);
    }

    /**
     * Create a custom analytics dashboard with real-time data.
     */
    private function customAnalyticsDashboard(): void
    {
        $dashboardTask = AnonymousTask::view('analytics-dashboard', 'task-runner::analytics-dashboard', [
            'metrics' => [
                'system_performance' => ['cpu', 'memory', 'disk'],
                'application_metrics' => ['requests', 'errors', 'response_time'],
                'business_metrics' => ['users', 'transactions', 'revenue'],
            ],
            'refresh_interval' => 10,
            'chart_types' => ['line', 'bar', 'gauge', 'table'],
        ])
            ->withName('Custom Analytics Dashboard')
            ->withTimeout(300);

        TaskRunner::run($dashboardTask);
    }

    /**
     * Streaming data processing pipeline.
     */
    private function streamingDataPipeline(): void
    {
        $pipelineTask = AnonymousTask::command('data-pipeline', <<<'BASH'
#!/bin/bash

# Streaming data processing pipeline
echo "Starting streaming data processing pipeline..."

# Create data processing pipeline
PIPELINE_STAGES=("ingest" "transform" "enrich" "aggregate" "output")
DATA_SOURCE="/tmp/streaming_data.jsonl"
OUTPUT_FILE="/tmp/processed_data.json"

# Stage 1: Data Ingestion
echo "Stage 1: Data Ingestion"
cat > $DATA_SOURCE << 'EOF'
{"timestamp": "2024-01-01T10:00:00Z", "user_id": 1, "action": "login", "value": 100}
{"timestamp": "2024-01-01T10:01:00Z", "user_id": 2, "action": "purchase", "value": 250}
{"timestamp": "2024-01-01T10:02:00Z", "user_id": 1, "action": "logout", "value": 0}
{"timestamp": "2024-01-01T10:03:00Z", "user_id": 3, "action": "login", "value": 75}
{"timestamp": "2024-01-01T10:04:00Z", "user_id": 2, "action": "view", "value": 50}
EOF

# Stage 2: Data Transformation
echo "Stage 2: Data Transformation"
cat $DATA_SOURCE | jq -c '. + {"processed_at": now, "stage": "transformed"}' > /tmp/transformed_data.jsonl

# Stage 3: Data Enrichment
echo "Stage 3: Data Enrichment"
cat /tmp/transformed_data.jsonl | jq -c '. + {
    "enriched": true,
    "user_segment": (if .user_id == 1 then "premium" elif .user_id == 2 then "regular" else "new" end),
    "action_category": (if .action == "login" or .action == "logout" then "auth" elif .action == "purchase" then "transaction" else "view" end)
}' > /tmp/enriched_data.jsonl

# Stage 4: Data Aggregation
echo "Stage 4: Data Aggregation"
cat /tmp/enriched_data.jsonl | jq -s 'group_by(.action_category) | map({
    category: .[0].action_category,
    count: length,
    total_value: map(.value) | add,
    avg_value: map(.value) | add / length,
    users: map(.user_id) | unique | length
})' > $OUTPUT_FILE

# Stage 5: Output Generation
echo "Stage 5: Output Generation"
echo "Processing complete. Results:"
cat $OUTPUT_FILE

# Generate streaming metrics
echo "Generating streaming metrics..."
cat /tmp/enriched_data.jsonl | jq -r '.timestamp' | while read timestamp; do
    echo "Processing event at: $timestamp"
    sleep 0.1
done

echo "Pipeline completed successfully"
BASH
        )
            ->withName('Streaming Data Pipeline')
            ->withTimeout(180);

        TaskRunner::run($pipelineTask);
    }

    /**
     * Real-time alerting system.
     */
    private function realTimeAlerting(): void
    {
        $alertingTask = AnonymousTask::command('real-time-alerting', <<<'BASH'
#!/bin/bash

# Real-time alerting system
echo "Starting real-time alerting system..."

# Define alert thresholds
CPU_THRESHOLD=80
MEMORY_THRESHOLD=85
DISK_THRESHOLD=90
ERROR_RATE_THRESHOLD=5

# Alert configuration
ALERT_CHANNELS=("email" "slack" "webhook")
ALERT_COOLDOWN=300  # 5 minutes

# Initialize alert state
ALERT_STATE_FILE="/tmp/alert_state.json"
cat > $ALERT_STATE_FILE << EOF
{
  "cpu_alert": {"triggered": false, "last_triggered": null},
  "memory_alert": {"triggered": false, "last_triggered": null},
  "disk_alert": {"triggered": false, "last_triggered": null},
  "error_alert": {"triggered": false, "last_triggered": null}
}
EOF

# Function to send alerts
send_alert() {
    local alert_type=$1
    local message=$2
    local severity=$3
    
    echo "ALERT [$severity]: $alert_type - $message"
    
    # Update alert state
    jq ".${alert_type}_alert.triggered = true | .${alert_type}_alert.last_triggered = \"$(date -Iseconds)\"" $ALERT_STATE_FILE > /tmp/temp_state.json
    mv /tmp/temp_state.json $ALERT_STATE_FILE
    
    # Send to different channels
    for channel in "${ALERT_CHANNELS[@]}"; do
        case $channel in
            "email")
                echo "Sending email alert: $message"
                ;;
            "slack")
                echo "Sending Slack alert: $message"
                ;;
            "webhook")
                echo "Sending webhook alert: $message"
                ;;
        esac
    done
}

# Function to check if alert should be sent (cooldown)
should_send_alert() {
    local alert_type=$1
    local last_triggered=$(jq -r ".${alert_type}_alert.last_triggered" $ALERT_STATE_FILE)
    
    if [[ "$last_triggered" == "null" ]]; then
        return 0
    fi
    
    local last_epoch=$(date -d "$last_triggered" +%s 2>/dev/null || echo "0")
    local current_epoch=$(date +%s)
    local time_diff=$((current_epoch - last_epoch))
    
    [[ $time_diff -gt $ALERT_COOLDOWN ]]
}

# Monitor system metrics
for i in {1..10}; do
    echo "Monitoring cycle $i/10..."
    
    # Get CPU usage
    cpu_usage=$(top -bn1 | grep "Cpu(s)" | awk '{print $2}' | cut -d'%' -f1 | cut -d'.' -f1)
    
    # Get memory usage
    memory_usage=$(free | grep Mem | awk '{printf "%.0f", $3/$2 * 100.0}')
    
    # Get disk usage
    disk_usage=$(df / | tail -1 | awk '{print $5}' | sed 's/%//')
    
    # Simulate error rate
    error_rate=$((RANDOM % 10))
    
    echo "Current metrics - CPU: ${cpu_usage}%, Memory: ${memory_usage}%, Disk: ${disk_usage}%, Error Rate: ${error_rate}%"
    
    # Check CPU threshold
    if [[ $cpu_usage -gt $CPU_THRESHOLD ]] && should_send_alert "cpu"; then
        send_alert "cpu" "CPU usage is ${cpu_usage}% (threshold: ${CPU_THRESHOLD}%)" "WARNING"
    fi
    
    # Check memory threshold
    if [[ $memory_usage -gt $MEMORY_THRESHOLD ]] && should_send_alert "memory"; then
        send_alert "memory" "Memory usage is ${memory_usage}% (threshold: ${MEMORY_THRESHOLD}%)" "WARNING"
    fi
    
    # Check disk threshold
    if [[ $disk_usage -gt $DISK_THRESHOLD ]] && should_send_alert "disk"; then
        send_alert "disk" "Disk usage is ${disk_usage}% (threshold: ${DISK_THRESHOLD}%)" "CRITICAL"
    fi
    
    # Check error rate threshold
    if [[ $error_rate -gt $ERROR_RATE_THRESHOLD ]] && should_send_alert "error"; then
        send_alert "error" "Error rate is ${error_rate}% (threshold: ${ERROR_RATE_THRESHOLD}%)" "ERROR"
    fi
    
    sleep 10
done

echo "Alerting system completed"
BASH
        )
            ->withName('Real-time Alerting System')
            ->withTimeout(120);

        TaskRunner::run($alertingTask);
    }

    /**
     * Extract metric from output buffer.
     */
    private function extractMetric(string $buffer, string $metric): float
    {
        if (preg_match("/\"$metric\":\s*([0-9.]+)/", $buffer, $matches)) {
            return (float) $matches[1];
        }

        return 0.0;
    }
}
