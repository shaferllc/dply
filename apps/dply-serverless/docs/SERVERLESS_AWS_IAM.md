# AWS IAM for dply-serverless (control plane + customer Lambda)

Two different trust boundaries:

1. **This Laravel app** (running on a VM, container, or Lambda via Bref) calling AWS on behalf of your product.
2. **Customer functions** updated via `UpdateFunctionCode` / S3 references — the IAM principal that runs the control plane must be allowed to call Lambda and (if used) read S3 artifacts.

## Control plane runtime (SDK provisioner, `SERVERLESS_PROVISIONER=aws`)

Attach a policy similar to the following to the role or user whose credentials the app uses (`AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` or instance/task role).

Minimal for **describe + direct zip + S3-based code updates** on known functions:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "LambdaDescribeAndPublish",
      "Effect": "Allow",
      "Action": [
        "lambda:GetFunction",
        "lambda:UpdateFunctionCode"
      ],
      "Resource": "arn:aws:lambda:REGION:ACCOUNT_ID:function:FUNCTION_NAME"
    },
    {
      "Sid": "S3ReadArtifacts",
      "Effect": "Allow",
      "Action": ["s3:GetObject", "s3:GetObjectVersion"],
      "Resource": "arn:aws:s3:::YOUR_ALLOWED_BUCKET/*"
    }
  ]
}
```

- Replace `REGION`, `ACCOUNT_ID`, `FUNCTION_NAME`, and `YOUR_ALLOWED_BUCKET` with values that match your deployment. Tighten `Resource` ARNs; avoid `*` in production.
- **S3 statement** is only needed when deploy payloads use **`s3://bucket/key`** and **`SERVERLESS_AWS_S3_ALLOW_BUCKETS`** includes that bucket. Lambda pulls the object using **the same credentials as the control plane** only when you use direct-upload APIs from the SDK; for **`S3Bucket`/`S3Key` on `UpdateFunctionCode`**, **Lambda’s execution role** must also allow `s3:GetObject` on that object (separate from the control plane).

## Zip uploads from disk

When **`SERVERLESS_AWS_UPLOAD_ZIP=true`** and **`SERVERLESS_AWS_ZIP_PATH_PREFIX`** is set, the app reads bytes locally and calls `UpdateFunctionCode` with `ZipFile`. No S3 read permission is required for that path; **`lambda:UpdateFunctionCode`** on the target function is still required.

## Hardening

- Keep **`SERVERLESS_AWS_S3_ALLOW_BUCKETS`** to the smallest set of buckets you use for build artifacts.
- Prefer **short-lived CI roles** and **scoped function ARNs** over account-wide `lambda:*`.

## Bref / Lambda hosting of this app

When the **control plane itself** runs on Lambda (see `serverless.yml`), grant that function’s role:

- **API Gateway / HTTP** integration (managed by Bref).
- **CloudWatch Logs** (default for Lambda).
- **SQS `SendMessage`** on the app’s **jobs queue** (see **`serverless.yml`** / **[BREF_LAMBDA_QUEUE.md](./BREF_LAMBDA_QUEUE.md)**).
- **RDS / S3** when you wire database and optional asset storage in Laravel + IaC.

Do **not** reuse the customer-Lambda policy above for the Bref web role unless you intend the control plane identity to update customer functions (then scope resources tightly).
