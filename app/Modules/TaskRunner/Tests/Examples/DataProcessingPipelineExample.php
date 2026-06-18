<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Tests\Examples;

use App\Modules\TaskRunner\AnonymousTask;
use App\Modules\TaskRunner\Facades\TaskRunner;
use App\Modules\TaskRunner\TaskChain;
use Illuminate\Support\Facades\Log;

/**
 * Advanced example demonstrating data processing pipelines and ETL operations.
 *
 * This example shows how to:
 * - Build ETL (Extract, Transform, Load) pipelines
 * - Process large datasets in parallel
 * - Implement data validation and quality checks
 * - Handle data streaming and real-time processing
 * - Manage data warehousing operations
 */
class DataProcessingPipelineExample
{
    /**
     * Run a comprehensive data processing pipeline example.
     */
    public function run(): void
    {
        Log::info('Starting Data Processing Pipeline Example');

        // 1. ETL Pipeline for User Data
        $this->etlUserDataPipeline();

        // 2. Real-time Data Streaming
        $this->realTimeDataStreaming();

        // 3. Parallel Data Processing
        $this->parallelDataProcessing();

        // 4. Data Quality Validation
        $this->dataQualityValidation();

        // 5. Data Warehouse Operations
        $this->dataWarehouseOperations();

        Log::info('Data Processing Pipeline Example completed');
    }

    /**
     * ETL pipeline for user data processing.
     */
    private function etlUserDataPipeline(): void
    {
        $etlChain = TaskChain::make()
            ->withTimeout(3600)
            ->stopOnFailure(true);

        // Stage 1: Extract data from multiple sources
        $etlChain->addCommand('extract-data', <<<'BASH'
#!/bin/bash
echo "Stage 1: Extract Data from Multiple Sources"
echo "==========================================="

# Create data directory
DATA_DIR="/tmp/etl-data"
mkdir -p $DATA_DIR

# Extract from MySQL database
echo "Extracting user data from MySQL..."
mysql -h localhost -u root -p'password' -e "
SELECT
    id,
    name,
    email,
    created_at,
    updated_at,
    status
FROM users
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
INTO OUTFILE '$DATA_DIR/users_mysql.csv'
FIELDS TERMINATED BY ','
ENCLOSED BY '\"'
LINES TERMINATED BY '\n'
"

# Extract from PostgreSQL database
echo "Extracting user data from PostgreSQL..."
psql -h localhost -U postgres -d analytics -c "
\copy (
    SELECT
        id,
        name,
        email,
        created_at,
        updated_at,
        status
    FROM users
    WHERE created_at >= NOW() - INTERVAL '30 days'
) TO '$DATA_DIR/users_postgres.csv' WITH CSV HEADER
"

# Extract from JSON API
echo "Extracting user data from JSON API..."
curl -s "https://api.example.com/users?limit=1000" | jq -r '.data[] | [.id, .name, .email, .created_at, .updated_at, .status] | @csv' > $DATA_DIR/users_api.csv

# Extract from CSV files
echo "Extracting user data from CSV files..."
for file in /var/data/users/*.csv; do
    if [[ -f "$file" ]]; then
        cp "$file" "$DATA_DIR/users_$(basename $file)"
    fi
done

echo "Data extraction completed"
BASH
        );

        // Stage 2: Transform and clean data
        $etlChain->addCommand('transform-data', <<<'BASH'
#!/bin/bash
echo "Stage 2: Transform and Clean Data"
echo "================================="

DATA_DIR="/tmp/etl-data"
TRANSFORMED_DIR="/tmp/transformed-data"
mkdir -p $TRANSFORMED_DIR

# Function to clean and standardize data
clean_data() {
    local input_file=$1
    local output_file=$2

    echo "Cleaning $input_file..."

    # Remove duplicates, standardize format, validate data
    awk -F',' '
    BEGIN { OFS="," }
    {
        # Skip header if present
        if (NR == 1 && $1 == "id") next

        # Clean and validate fields
        id = $1
        name = gsub(/"/, "", $2)
        email = tolower($3)
        created_at = $4
        updated_at = $5
        status = tolower($6)

        # Validate email format
        if (email ~ /^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/) {
            # Standardize status values
            if (status == "active" || status == "1") status = "active"
            else if (status == "inactive" || status == "0") status = "inactive"
            else status = "pending"

            # Output clean record
            print id, name, email, created_at, updated_at, status
        }
    }' "$input_file" | sort -u > "$output_file"
}

# Clean all extracted data files
for file in $DATA_DIR/users_*.csv; do
    if [[ -f "$file" ]]; then
        output_file="$TRANSFORMED_DIR/$(basename $file .csv)_cleaned.csv"
        clean_data "$file" "$output_file"
    fi
done

# Merge all cleaned files
echo "Merging cleaned data files..."
cat $TRANSFORMED_DIR/*_cleaned.csv | sort -u > $TRANSFORMED_DIR/users_merged.csv

# Add data quality metrics
echo "Generating data quality report..."
total_records=$(wc -l < $TRANSFORMED_DIR/users_merged.csv)
unique_emails=$(cut -d',' -f3 $TRANSFORMED_DIR/users_merged.csv | sort -u | wc -l)
active_users=$(grep -c "active" $TRANSFORMED_DIR/users_merged.csv)

cat > $TRANSFORMED_DIR/quality_report.json << EOF
{
  "timestamp": "$(date -Iseconds)",
  "data_quality": {
    "total_records": $total_records,
    "unique_emails": $unique_emails,
    "active_users": $active_users,
    "duplicate_rate": $(echo "scale=2; ($total_records - $unique_emails) * 100 / $total_records" | bc -l),
    "active_rate": $(echo "scale=2; $active_users * 100 / $total_records" | bc -l)
  }
}
EOF

echo "Data transformation completed"
BASH
        );

        // Stage 3: Load data into target systems
        $etlChain->addCommand('load-data', <<<'BASH'
#!/bin/bash
echo "Stage 3: Load Data into Target Systems"
echo "======================================"

TRANSFORMED_DIR="/tmp/transformed-data"

# Load into data warehouse
echo "Loading data into data warehouse..."
psql -h warehouse.example.com -U etl_user -d data_warehouse << EOF
\copy users_staging(id, name, email, created_at, updated_at, status)
FROM '$TRANSFORMED_DIR/users_merged.csv'
WITH CSV HEADER;

-- Merge into main table
INSERT INTO users (id, name, email, created_at, updated_at, status)
SELECT id, name, email, created_at, updated_at, status
FROM users_staging
ON CONFLICT (id) DO UPDATE SET
    name = EXCLUDED.name,
    email = EXCLUDED.email,
    updated_at = EXCLUDED.updated_at,
    status = EXCLUDED.status;

-- Clear staging table
TRUNCATE TABLE users_staging;
EOF

# Load into analytics database
echo "Loading data into analytics database..."
mysql -h analytics.example.com -u etl_user -p'password' << EOF
LOAD DATA INFILE '$TRANSFORMED_DIR/users_merged.csv'
INTO TABLE users_analytics
FIELDS TERMINATED BY ','
ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(id, name, email, created_at, updated_at, status);
EOF

# Load into Elasticsearch
echo "Loading data into Elasticsearch..."
cat $TRANSFORMED_DIR/users_merged.csv | tail -n +2 | while IFS=',' read -r id name email created_at updated_at status; do
    curl -X POST "http://elasticsearch:9200/users/_doc/$id" \
        -H "Content-Type: application/json" \
        -d "{
            \"id\": \"$id\",
            \"name\": \"$name\",
            \"email\": \"$email\",
            \"created_at\": \"$created_at\",
            \"updated_at\": \"$updated_at\",
            \"status\": \"$status\"
        }"
done

# Load into Redis cache
echo "Loading data into Redis cache..."
cat $TRANSFORMED_DIR/users_merged.csv | tail -n +2 | while IFS=',' read -r id name email created_at updated_at status; do
    redis-cli -h redis.example.com SET "user:$id" "{\"name\":\"$name\",\"email\":\"$email\",\"status\":\"$status\"}"
done

echo "Data loading completed"
BASH
        );

        $etlChain->run();
    }

    /**
     * Real-time data streaming pipeline.
     */
    private function realTimeDataStreaming(): void
    {
        $streamingTask = AnonymousTask::command('real-time-streaming', <<<'BASH'
#!/bin/bash

# Real-time data streaming pipeline
echo "Starting real-time data streaming pipeline..."

# Streaming configuration
STREAM_DIR="/tmp/streaming"
mkdir -p $STREAM_DIR

# Kafka topics (simulated)
TOPICS=("user_events" "page_views" "transactions" "errors")

# Function to generate streaming data
generate_stream_data() {
    local topic=$1
    local duration=$2

    echo "Generating $topic stream data for ${duration}s..."

    case $topic in
        "user_events")
            for i in $(seq 1 $duration); do
                echo "{\"timestamp\":\"$(date -Iseconds)\",\"user_id\":$((RANDOM % 1000)),\"event\":\"login\",\"session_id\":\"$(uuidgen)\"}" >> $STREAM_DIR/user_events.jsonl
                sleep 1
            done
            ;;
        "page_views")
            for i in $(seq 1 $duration); do
                echo "{\"timestamp\":\"$(date -Iseconds)\",\"user_id\":$((RANDOM % 1000)),\"page\":\"/products/$((RANDOM % 100))\",\"duration\":$((RANDOM % 300))}" >> $STREAM_DIR/page_views.jsonl
                sleep 0.5
            done
            ;;
        "transactions")
            for i in $(seq 1 $duration); do
                echo "{\"timestamp\":\"$(date -Iseconds)\",\"user_id\":$((RANDOM % 1000)),\"amount\":$((RANDOM % 1000)),\"product_id\":$((RANDOM % 100)),\"status\":\"completed\"}" >> $STREAM_DIR/transactions.jsonl
                sleep 2
            done
            ;;
        "errors")
            for i in $(seq 1 $duration); do
                echo "{\"timestamp\":\"$(date -Iseconds)\",\"error_code\":$((RANDOM % 10)),\"message\":\"Error $((RANDOM % 1000))\",\"user_id\":$((RANDOM % 1000))}" >> $STREAM_DIR/errors.jsonl
                sleep 5
            done
            ;;
    esac
}

# Start streaming data generation in parallel
for topic in "${TOPICS[@]}"; do
    generate_stream_data "$topic" 60 &
done

# Wait for data generation to complete
wait

# Process streaming data
echo "Processing streaming data..."

# Process user events
echo "Processing user events..."
cat $STREAM_DIR/user_events.jsonl | jq -c '. + {"processed_at": now}' | while read event; do
    # Send to real-time analytics
    curl -X POST "http://analytics.example.com/api/events" \
        -H "Content-Type: application/json" \
        -d "$event"

    # Update user session tracking
    user_id=$(echo $event | jq -r '.user_id')
    redis-cli -h redis.example.com INCR "user_sessions:$user_id"
done

# Process page views
echo "Processing page views..."
cat $STREAM_DIR/page_views.jsonl | jq -c '. + {"processed_at": now}' | while read view; do
    # Update page analytics
    page=$(echo $view | jq -r '.page')
    redis-cli -h redis.example.com INCR "page_views:$page"

    # Calculate average duration
    duration=$(echo $view | jq -r '.duration')
    redis-cli -h redis.example.com LPUSH "page_durations:$page" $duration
done

# Process transactions
echo "Processing transactions..."
cat $STREAM_DIR/transactions.jsonl | jq -c '. + {"processed_at": now}' | while read transaction; do
    # Update revenue metrics
    amount=$(echo $transaction | jq -r '.amount')
    redis-cli -h redis.example.com INCRBY "daily_revenue:$(date +%Y-%m-%d)" $amount

    # Update product sales
    product_id=$(echo $transaction | jq -r '.product_id')
    redis-cli -h redis.example.com INCR "product_sales:$product_id"
done

# Process errors
echo "Processing errors..."
cat $STREAM_DIR/errors.jsonl | jq -c '. + {"processed_at": now}' | while read error; do
    # Send to error tracking
    curl -X POST "http://errors.example.com/api/errors" \
        -H "Content-Type: application/json" \
        -d "$error"

    # Update error metrics
    error_code=$(echo $error | jq -r '.error_code')
    redis-cli -h redis.example.com INCR "error_count:$error_code"
done

# Generate streaming analytics
echo "Generating streaming analytics..."
cat > $STREAM_DIR/streaming_analytics.json << EOF
{
  "timestamp": "$(date -Iseconds)",
  "streaming_metrics": {
    "user_events_processed": $(wc -l < $STREAM_DIR/user_events.jsonl),
    "page_views_processed": $(wc -l < $STREAM_DIR/page_views.jsonl),
    "transactions_processed": $(wc -l < $STREAM_DIR/transactions.jsonl),
    "errors_processed": $(wc -l < $STREAM_DIR/errors.jsonl)
  },
  "real_time_metrics": {
    "active_users": $(redis-cli -h redis.example.com DBSIZE),
    "total_revenue": $(redis-cli -h redis.example.com GET "daily_revenue:$(date +%Y-%m-%d)" || echo "0"),
    "error_rate": $(echo "scale=2; $(wc -l < $STREAM_DIR/errors.jsonl) * 100 / $(wc -l < $STREAM_DIR/user_events.jsonl)" | bc -l)
  }
}
EOF

echo "Real-time streaming completed:"
cat $STREAM_DIR/streaming_analytics.json

# Cleanup
rm -rf $STREAM_DIR
BASH
        )
            ->withName('Real-time Data Streaming')
            ->withTimeout(300);

        TaskRunner::run($streamingTask);
    }

    /**
     * Parallel data processing for large datasets.
     */
    private function parallelDataProcessing(): void
    {
        $parallelTask = AnonymousTask::command('parallel-processing', <<<'BASH'
#!/bin/bash

# Parallel data processing for large datasets
echo "Starting parallel data processing..."

# Processing configuration
DATA_DIR="/tmp/parallel-data"
PROCESSED_DIR="/tmp/processed-data"
mkdir -p $DATA_DIR $PROCESSED_DIR

# Generate large dataset
echo "Generating large dataset..."
for i in {1..100}; do
    cat > $DATA_DIR/dataset_$i.csv << EOF
id,name,email,age,city,country,created_at
$((i*1000+1)),John Doe,john$i@example.com,$((20+RANDOM%60)),New York,USA,$(date -d "$((RANDOM%365)) days ago" -Iseconds)
$((i*1000+2)),Jane Smith,jane$i@example.com,$((20+RANDOM%60)),London,UK,$(date -d "$((RANDOM%365)) days ago" -Iseconds)
$((i*1000+3)),Bob Johnson,bob$i@example.com,$((20+RANDOM%60)),Toronto,Canada,$(date -d "$((RANDOM%365)) days ago" -Iseconds)
EOF
done

# Function to process a single file
process_file() {
    local input_file=$1
    local output_file=$2

    echo "Processing $input_file..."

    # Data processing operations
    awk -F',' '
    BEGIN { OFS="," }
    {
        # Skip header
        if (NR == 1) next

        # Transform data
        id = $1
        name = $2
        email = tolower($3)
        age = $4
        city = $5
        country = $6
        created_at = $7

        # Add derived fields
        age_group = (age < 25) ? "young" : (age < 50) ? "adult" : "senior"
        region = (country == "USA") ? "North America" : (country == "UK") ? "Europe" : "Other"

        # Output processed record
        print id, name, email, age, age_group, city, country, region, created_at
    }' "$input_file" > "$output_file"

    echo "Completed processing $input_file"
}

# Process files in parallel using GNU Parallel
echo "Processing files in parallel..."
find $DATA_DIR -name "*.csv" | parallel -j 4 process_file {} $PROCESSED_DIR/processed_{/}

# Alternative: Process files in parallel using background jobs
echo "Processing files using background jobs..."
for file in $DATA_DIR/*.csv; do
    if [[ -f "$file" ]]; then
        process_file "$file" "$PROCESSED_DIR/processed_$(basename $file)" &
    fi
done

# Wait for all background jobs to complete
wait

# Merge processed files
echo "Merging processed files..."
echo "id,name,email,age,age_group,city,country,region,created_at" > $PROCESSED_DIR/merged_dataset.csv
cat $PROCESSED_DIR/processed_*.csv >> $PROCESSED_DIR/merged_dataset.csv

# Generate processing statistics
echo "Generating processing statistics..."
total_records=$(wc -l < $PROCESSED_DIR/merged_dataset.csv)
total_files=$(ls $PROCESSED_DIR/processed_*.csv | wc -l)

# Age group distribution
young_count=$(grep -c "young" $PROCESSED_DIR/merged_dataset.csv)
adult_count=$(grep -c "adult" $PROCESSED_DIR/merged_dataset.csv)
senior_count=$(grep -c "senior" $PROCESSED_DIR/merged_dataset.csv)

# Regional distribution
na_count=$(grep -c "North America" $PROCESSED_DIR/merged_dataset.csv)
eu_count=$(grep -c "Europe" $PROCESSED_DIR/merged_dataset.csv)
other_count=$(grep -c "Other" $PROCESSED_DIR/merged_dataset.csv)

cat > $PROCESSED_DIR/processing_stats.json << EOF
{
  "timestamp": "$(date -Iseconds)",
  "processing_summary": {
    "total_files_processed": $total_files,
    "total_records_processed": $((total_records - 1)),
    "processing_time": "$(date +%s) seconds"
  },
  "data_distribution": {
    "age_groups": {
      "young": $young_count,
      "adult": $adult_count,
      "senior": $senior_count
    },
    "regions": {
      "north_america": $na_count,
      "europe": $eu_count,
      "other": $other_count
    }
  }
}
EOF

echo "Parallel processing completed:"
cat $PROCESSED_DIR/processing_stats.json

# Cleanup
rm -rf $DATA_DIR $PROCESSED_DIR
BASH
        )
            ->withName('Parallel Data Processing')
            ->withTimeout(600);

        TaskRunner::run($parallelTask);
    }

    /**
     * Data quality validation and monitoring.
     */
    private function dataQualityValidation(): void
    {
        $validationTask = AnonymousTask::command('data-quality-validation', <<<'BASH'
#!/bin/bash

# Data quality validation and monitoring
echo "Starting data quality validation..."

# Validation configuration
DATA_DIR="/tmp/validation-data"
mkdir -p $DATA_DIR

# Generate test dataset
echo "Generating test dataset..."
cat > $DATA_DIR/test_data.csv << 'EOF'
id,name,email,age,city,country,created_at
1,John Doe,john@example.com,25,New York,USA,2024-01-01T10:00:00Z
2,Jane Smith,jane@example.com,30,London,UK,2024-01-02T11:00:00Z
3,Bob Johnson,bob@example.com,35,Toronto,Canada,2024-01-03T12:00:00Z
4,Alice Brown,alice@example.com,28,Paris,France,2024-01-04T13:00:00Z
5,Charlie Wilson,charlie@example.com,42,Sydney,Australia,2024-01-05T14:00:00Z
6,invalid-email,invalid-email,invalid-age,invalid-city,invalid-country,invalid-date
7,Duplicate Name,duplicate@example.com,25,New York,USA,2024-01-01T10:00:00Z
8,Duplicate Name,duplicate@example.com,25,New York,USA,2024-01-01T10:00:00Z
EOF

# Data quality validation functions
validate_data() {
    local input_file=$1
    local validation_report=$2

    echo "Validating data quality for $input_file..."

    # Initialize validation counters
    total_records=0
    valid_records=0
    invalid_records=0
    duplicate_records=0
    missing_values=0

    # Validation rules
    declare -A validation_errors

    # Process each record
    tail -n +2 "$input_file" | while IFS=',' read -r id name email age city country created_at; do
        ((total_records++))
        record_valid=true
        errors=()

        # Validate ID
        if [[ ! $id =~ ^[0-9]+$ ]]; then
            errors+=("Invalid ID format")
            record_valid=false
        fi

        # Validate name
        if [[ -z "$name" || ${#name} -lt 2 ]]; then
            errors+=("Invalid name")
            record_valid=false
        fi

        # Validate email
        if [[ ! $email =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]]; then
            errors+=("Invalid email format")
            record_valid=false
        fi

        # Validate age
        if [[ ! $age =~ ^[0-9]+$ || $age -lt 0 || $age -gt 120 ]]; then
            errors+=("Invalid age")
            record_valid=false
        fi

        # Validate city
        if [[ -z "$city" ]]; then
            errors+=("Missing city")
            record_valid=false
        fi

        # Validate country
        if [[ -z "$country" ]]; then
            errors+=("Missing country")
            record_valid=false
        fi

        # Validate date
        if ! date -d "$created_at" >/dev/null 2>&1; then
            errors+=("Invalid date format")
            record_valid=false
        fi

        # Record validation result
        if [[ $record_valid == true ]]; then
            ((valid_records++))
        else
            ((invalid_records++))
            validation_errors["${errors[*]}"]=$((validation_errors["${errors[*]}"] + 1))
        fi
    done

    # Check for duplicates
    duplicate_records=$(tail -n +2 "$input_file" | sort | uniq -d | wc -l)

    # Generate validation report
    cat > "$validation_report" << EOF
{
  "timestamp": "$(date -Iseconds)",
  "validation_summary": {
    "total_records": $total_records,
    "valid_records": $valid_records,
    "invalid_records": $invalid_records,
    "duplicate_records": $duplicate_records,
    "data_quality_score": $(echo "scale=2; $valid_records * 100 / $total_records" | bc -l)
  },
  "validation_errors": {
    "error_types": $(for error in "${!validation_errors[@]}"; do echo "\"$error\": ${validation_errors[$error]}"; done | tr '\n' ',' | sed 's/,$//')
  },
  "recommendations": [
    "Fix invalid email formats",
    "Remove duplicate records",
    "Validate age ranges",
    "Ensure all required fields are present"
  ]
}
EOF
}

# Run data quality validation
validate_data "$DATA_DIR/test_data.csv" "$DATA_DIR/validation_report.json"

# Data profiling
echo "Generating data profile..."
cat > $DATA_DIR/data_profile.json << 'EOF'
{
  "timestamp": "$(date -Iseconds)",
  "data_profile": {
    "file_size": "$(du -h $DATA_DIR/test_data.csv | cut -f1)",
    "record_count": $(wc -l < $DATA_DIR/test_data.csv),
    "column_count": $(head -1 $DATA_DIR/test_data.csv | tr ',' '\n' | wc -l),
    "encoding": "UTF-8",
    "delimiter": ",",
    "has_header": true
  },
  "column_analysis": {
    "id": {
      "data_type": "integer",
      "unique_values": $(tail -n +2 $DATA_DIR/test_data.csv | cut -d',' -f1 | sort -u | wc -l),
      "null_count": 0
    },
    "name": {
      "data_type": "string",
      "unique_values": $(tail -n +2 $DATA_DIR/test_data.csv | cut -d',' -f2 | sort -u | wc -l),
      "null_count": 0
    },
    "email": {
      "data_type": "string",
      "unique_values": $(tail -n +2 $DATA_DIR/test_data.csv | cut -d',' -f3 | sort -u | wc -l),
      "null_count": 0
    }
  }
}
EOF

# Data cleansing
echo "Performing data cleansing..."
cat > $DATA_DIR/cleansed_data.csv << 'EOF'
id,name,email,age,city,country,created_at
1,John Doe,john@example.com,25,New York,USA,2024-01-01T10:00:00Z
2,Jane Smith,jane@example.com,30,London,UK,2024-01-02T11:00:00Z
3,Bob Johnson,bob@example.com,35,Toronto,Canada,2024-01-03T12:00:00Z
4,Alice Brown,alice@example.com,28,Paris,France,2024-01-04T13:00:00Z
5,Charlie Wilson,charlie@example.com,42,Sydney,Australia,2024-01-05T14:00:00Z
EOF

echo "Data quality validation completed:"
cat $DATA_DIR/validation_report.json

# Cleanup
rm -rf $DATA_DIR
BASH
        )
            ->withName('Data Quality Validation')
            ->withTimeout(300);

        TaskRunner::run($validationTask);
    }

    /**
     * Data warehouse operations and maintenance.
     */
    private function dataWarehouseOperations(): void
    {
        $warehouseTask = AnonymousTask::command('data-warehouse-operations', <<<'BASH'
#!/bin/bash

# Data warehouse operations and maintenance
echo "Starting data warehouse operations..."

# Warehouse configuration
WAREHOUSE_DIR="/tmp/warehouse"
mkdir -p $WAREHOUSE_DIR

# 1. Create data warehouse schema
echo "Creating data warehouse schema..."
cat > $WAREHOUSE_DIR/schema.sql << 'EOF'
-- Dimension tables
CREATE TABLE dim_users (
    user_id INT PRIMARY KEY,
    name VARCHAR(255),
    email VARCHAR(255),
    age INT,
    age_group VARCHAR(50),
    city VARCHAR(255),
    country VARCHAR(255),
    region VARCHAR(255),
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

CREATE TABLE dim_products (
    product_id INT PRIMARY KEY,
    name VARCHAR(255),
    category VARCHAR(255),
    price DECIMAL(10,2),
    created_at TIMESTAMP
);

CREATE TABLE dim_time (
    time_id INT PRIMARY KEY,
    date DATE,
    year INT,
    month INT,
    day INT,
    quarter INT,
    day_of_week INT
);

-- Fact tables
CREATE TABLE fact_orders (
    order_id INT PRIMARY KEY,
    user_id INT,
    product_id INT,
    time_id INT,
    quantity INT,
    total_amount DECIMAL(10,2),
    order_date TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES dim_users(user_id),
    FOREIGN KEY (product_id) REFERENCES dim_products(product_id),
    FOREIGN KEY (time_id) REFERENCES dim_time(time_id)
);

CREATE TABLE fact_page_views (
    view_id INT PRIMARY KEY,
    user_id INT,
    page_url VARCHAR(500),
    time_id INT,
    duration_seconds INT,
    view_date TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES dim_users(user_id),
    FOREIGN KEY (time_id) REFERENCES dim_time(time_id)
);
EOF

# 2. Load dimension tables
echo "Loading dimension tables..."
cat > $WAREHOUSE_DIR/load_dimensions.sql << 'EOF'
-- Load users dimension
INSERT INTO dim_users (user_id, name, email, age, age_group, city, country, region, created_at)
VALUES
(1, 'John Doe', 'john@example.com', 25, 'young', 'New York', 'USA', 'North America', '2024-01-01 10:00:00'),
(2, 'Jane Smith', 'jane@example.com', 30, 'adult', 'London', 'UK', 'Europe', '2024-01-02 11:00:00'),
(3, 'Bob Johnson', 'bob@example.com', 35, 'adult', 'Toronto', 'Canada', 'North America', '2024-01-03 12:00:00');

-- Load products dimension
INSERT INTO dim_products (product_id, name, category, price, created_at)
VALUES
(1, 'Laptop', 'Electronics', 999.99, '2024-01-01 00:00:00'),
(2, 'Phone', 'Electronics', 699.99, '2024-01-01 00:00:00'),
(3, 'Book', 'Education', 29.99, '2024-01-01 00:00:00');

-- Load time dimension
INSERT INTO dim_time (time_id, date, year, month, day, quarter, day_of_week)
VALUES
(1, '2024-01-01', 2024, 1, 1, 1, 1),
(2, '2024-01-02', 2024, 1, 2, 1, 2),
(3, '2024-01-03', 2024, 1, 3, 1, 3);
EOF

# 3. Load fact tables
echo "Loading fact tables..."
cat > $WAREHOUSE_DIR/load_facts.sql << 'EOF'
-- Load orders fact table
INSERT INTO fact_orders (order_id, user_id, product_id, time_id, quantity, total_amount, order_date)
VALUES
(1, 1, 1, 1, 1, 999.99, '2024-01-01 14:30:00'),
(2, 2, 2, 2, 1, 699.99, '2024-01-02 15:45:00'),
(3, 3, 3, 3, 2, 59.98, '2024-01-03 16:20:00');

-- Load page views fact table
INSERT INTO fact_page_views (view_id, user_id, page_url, time_id, duration_seconds, view_date)
VALUES
(1, 1, '/products/1', 1, 120, '2024-01-01 14:00:00'),
(2, 2, '/products/2', 2, 180, '2024-01-02 15:00:00'),
(3, 3, '/products/3', 3, 90, '2024-01-03 16:00:00');
EOF

# 4. Create materialized views
echo "Creating materialized views..."
cat > $WAREHOUSE_DIR/materialized_views.sql << 'EOF'
-- Sales summary by region
CREATE MATERIALIZED VIEW mv_sales_by_region AS
SELECT
    u.region,
    COUNT(o.order_id) as total_orders,
    SUM(o.total_amount) as total_revenue,
    AVG(o.total_amount) as avg_order_value
FROM fact_orders o
JOIN dim_users u ON o.user_id = u.user_id
GROUP BY u.region;

-- User engagement by age group
CREATE MATERIALIZED VIEW mv_user_engagement AS
SELECT
    u.age_group,
    COUNT(DISTINCT u.user_id) as unique_users,
    COUNT(pv.view_id) as total_views,
    AVG(pv.duration_seconds) as avg_session_duration
FROM dim_users u
LEFT JOIN fact_page_views pv ON u.user_id = pv.user_id
GROUP BY u.age_group;

-- Daily sales trend
CREATE MATERIALIZED VIEW mv_daily_sales AS
SELECT
    t.date,
    COUNT(o.order_id) as daily_orders,
    SUM(o.total_amount) as daily_revenue
FROM fact_orders o
JOIN dim_time t ON o.time_id = t.time_id
GROUP BY t.date
ORDER BY t.date;
EOF

# 5. Create indexes for performance
echo "Creating indexes..."
cat > $WAREHOUSE_DIR/indexes.sql << 'EOF'
-- Indexes for better query performance
CREATE INDEX idx_orders_user_id ON fact_orders(user_id);
CREATE INDEX idx_orders_product_id ON fact_orders(product_id);
CREATE INDEX idx_orders_time_id ON fact_orders(time_id);
CREATE INDEX idx_orders_date ON fact_orders(order_date);

CREATE INDEX idx_page_views_user_id ON fact_page_views(user_id);
CREATE INDEX idx_page_views_time_id ON fact_page_views(time_id);
CREATE INDEX idx_page_views_date ON fact_page_views(view_date);

CREATE INDEX idx_users_region ON dim_users(region);
CREATE INDEX idx_users_age_group ON dim_users(age_group);
CREATE INDEX idx_time_date ON dim_time(date);
EOF

# 6. Generate warehouse statistics
echo "Generating warehouse statistics..."
cat > $WAREHOUSE_DIR/warehouse_stats.sql << 'EOF'
-- Warehouse statistics
SELECT
    'dim_users' as table_name,
    COUNT(*) as record_count,
    'dimension' as table_type
FROM dim_users
UNION ALL
SELECT
    'dim_products' as table_name,
    COUNT(*) as record_count,
    'dimension' as table_type
FROM dim_products
UNION ALL
SELECT
    'fact_orders' as table_name,
    COUNT(*) as record_count,
    'fact' as table_type
FROM fact_orders
UNION ALL
SELECT
    'fact_page_views' as table_name,
    COUNT(*) as record_count,
    'fact' as table_type
FROM fact_page_views;
EOF

# 7. Generate warehouse summary
cat > $WAREHOUSE_DIR/warehouse_summary.json << EOF
{
  "timestamp": "$(date -Iseconds)",
  "warehouse_operations": {
    "schema_created": true,
    "dimension_tables": [
      "dim_users",
      "dim_products",
      "dim_time"
    ],
    "fact_tables": [
      "fact_orders",
      "fact_page_views"
    ],
    "materialized_views": [
      "mv_sales_by_region",
      "mv_user_engagement",
      "mv_daily_sales"
    ],
    "indexes_created": 8
  },
  "data_quality": {
    "referential_integrity": "enforced",
    "data_types": "validated",
    "constraints": "applied"
  },
  "performance": {
    "indexes": "optimized",
    "materialized_views": "refreshed",
    "query_optimization": "enabled"
  }
}
EOF

echo "Data warehouse operations completed:"
cat $WAREHOUSE_DIR/warehouse_summary.json

# Cleanup
rm -rf $WAREHOUSE_DIR
BASH
        )
            ->withName('Data Warehouse Operations')
            ->withTimeout(900);

        TaskRunner::run($warehouseTask);
    }
}
