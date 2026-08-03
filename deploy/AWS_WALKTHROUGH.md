# Building the dply control plane on AWS — a hands-on walkthrough

A learning-oriented build. You type the commands; each step explains what the
AWS object *is*, why AWS makes you create it when Hetzner and Vultr don't, and
how to verify it worked.

Everything here is CLI rather than console clicking. Not purism — the CLI is
reproducible, it's what the provisioning code will eventually call, and it makes
teardown possible. Console clicking is how you end up with the orphaned
NAT/RDS/ALB the [teardown command](../app/Console/Commands/AwsControlPlaneTeardownCommand.php)
exists to clean up.

**Build it, tear it down, build it again.** The second pass is where it clicks.
Do the first pass on `t3.micro` everywhere — the topology is identical and it
costs pennies. Only size up on the real run.

Target: [CONTROL_PLANE_MIGRATION.md](CONTROL_PLANE_MIGRATION.md).

---



## The vocabulary, mapped to what you know


| Hetzner / Vultr                  | AWS                                           | The difference that bites                                                                                                     |
| -------------------------------- | --------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| Project                          | Account + IAM users                           | Credentials are per-account. Isolation means a whole separate account.                                                        |
| Private network (just works)     | VPC + subnet + internet gateway + route table | AWS makes you assemble the plumbing by hand. Four objects where Hetzner has one checkbox.                                     |
| Cloud firewall                   | Security group                                | **Stateful and allow-only** — no deny rules. Can reference *other security groups* as the source, which is better than CIDRs. |
| —                                | Network ACL                                   | Subnet-level, stateless, has deny rules. You will not need it. Ignore it.                                                     |
| SSH key in the panel             | EC2 key pair                                  | **Region-scoped.** A key in `us-west-2` doesn't exist in `us-east-1`.                                                      |
| Snapshot / image                 | AMI                                           | **Region- *and* architecture-scoped.** The arm64 and amd64 AMIs are different IDs.                                            |
| Plan slug (`cx22`, `vc2-2c-4gb`) | Instance type (`c6a.xlarge`)                  | family + size. The family letter encodes the CPU: `c`=compute, `m`=general, `t`=burstable, `a`=AMD, `g`=Graviton/ARM.         |
| Disk included with the plan      | EBS volume                                    | Separate object with its own lifecycle. Survives instance termination unless told otherwise.                                  |
| Floating / Reserved IP           | Elastic IP                                    | Free **while attached**, billed hourly when idle. Backwards from what you'd guess.                                            |
| API token                        | IAM access key                                | Scoped by policy. Never use the root account's.                                                                               |


---



## Step 0 — account hygiene (do not skip)

This is the part that determines whether the credits last or evaporate.

1. **Root account**: enable MFA, then stop using it. Root is for billing and
  account closure only.
2. **Create an IAM user** for yourself with `AdministratorAccess`, and an access
  key for the CLI. Everything below runs as that user.
3. **Billing alerts** — console → Billing → Budgets. Create a monthly cost budget
  with alerts at 50 / 75 / 90%. The console is genuinely easier than
   `aws budgets create-budget` here; this is the one step where clicking wins.
4. **Verify the credits** — Billing → Credits. Note the **amount and expiry**,
  and write them at the top of `CONTROL_PLANE_MIGRATION.md`. Check which
   services they're valid for.
5. **Region**: `us-west-2` (Oregon) — where the existing AWS footprint lives.
   Set it everywhere: the CLI profile *and* the console's region picker
   (top right). Both are region-scoped, and resources created in the wrong one
   are invisible from the right one. This is the most common console mistake.
   Tradeoff on record in [CONTROL_PLANE_MIGRATION.md](CONTROL_PLANE_MIGRATION.md):
   SSH round-trips to Hetzner `fsn1` customer servers now cross the Atlantic.

```bash
brew install awscli
aws configure --profile dply
#   AWS Access Key ID:     <from step 2>
#   AWS Secret Access Key: <from step 2>
#   Default region name:   us-west-2
#   Default output format: json

export AWS_PROFILE=dply
aws sts get-caller-identity          # who am I / which account
```

That last command should print your account ID and the IAM user ARN. If it
prints the root user, go back to step 2.

> ### Check the account before you create anything
>
> This machine has several AWS profiles configured, including client accounts,
> and `default` is **not** the dply account. Every command below inherits
> `AWS_PROFILE` — one command run with it unset builds dply infrastructure in
> whichever account `default` points at.
>
> ```bash
> export DPLY_AWS_ACCOUNT=891377105949        # the dply.io account
> test "$(aws sts get-caller-identity --query Account --output text)" \
>      = "$DPLY_AWS_ACCOUNT" \
>   && echo "OK — building in the dply account" \
>   || { echo "WRONG ACCOUNT — stop"; }
> ```
>
> Run that guard at the start of every session. Access keys, Activate credits,
> and billing are all per-account: if the credits are on one account and the
> instances on another, you are simply paying full price.



### Tag everything

Set this now, so every resource you create is findable later:

```bash
export TAGS='ResourceType=%s,Tags=[{Key=Name,Value=%s},{Key=dply,Value=control-plane}]'
```

The `dply=control-plane` tag is what makes the teardown in step 8 possible.
Untagged resources are how you get a surprise bill from something you forgot.

---



## Step 1 — the network

On Hetzner you tick "private network" and you're done. AWS makes you build four
objects. This is the single biggest conceptual difference, so it's worth
understanding rather than copy-pasting.

- **VPC** — your private IP space. Nothing inside it can reach the internet yet.
- **Subnet** — a slice of the VPC pinned to one availability zone. Instances
live in subnets, not in VPCs directly.
- **Internet Gateway (IGW)** — the door to the internet. Attaching it isn't
enough; nothing uses it until a route points at it.
- **Route table** — says "traffic for `0.0.0.0/0` goes to the IGW". *This* is
what makes a subnet "public".

A subnet is public or private purely because of its route table. There's no
checkbox — that's the whole trick.

```bash
# VPC
VPC=$(aws ec2 create-vpc --cidr-block 10.60.0.0/16 \
  --tag-specifications "$(printf "$TAGS" vpc dply-control-plane)" \
  --query 'Vpc.VpcId' --output text)
echo "VPC=$VPC"

# Subnet — one AZ. Single-AZ is an accepted risk for a control plane.
SUBNET=$(aws ec2 create-subnet --vpc-id "$VPC" \
  --cidr-block 10.60.1.0/24 --availability-zone us-west-2a \
  --tag-specifications "$(printf "$TAGS" subnet dply-cp-a)" \
  --query 'Subnet.SubnetId' --output text)

# Internet gateway, attached to the VPC
IGW=$(aws ec2 create-internet-gateway \
  --tag-specifications "$(printf "$TAGS" internet-gateway dply-cp-igw)" \
  --query 'InternetGateway.InternetGatewayId' --output text)
aws ec2 attach-internet-gateway --vpc-id "$VPC" --internet-gateway-id "$IGW"

# Route table with a default route to the IGW, associated with the subnet
RTB=$(aws ec2 create-route-table --vpc-id "$VPC" \
  --tag-specifications "$(printf "$TAGS" route-table dply-cp-rtb)" \
  --query 'RouteTable.RouteTableId' --output text)
aws ec2 create-route --route-table-id "$RTB" \
  --destination-cidr-block 0.0.0.0/0 --gateway-id "$IGW"
aws ec2 associate-route-table --route-table-id "$RTB" --subnet-id "$SUBNET"

# Give instances in this subnet a public IP automatically
aws ec2 modify-subnet-attribute --subnet-id "$SUBNET" --map-public-ip-on-launch
```

**Why no NAT Gateway.** The AWS reference architecture puts instances in a
*private* subnet and routes their outbound traffic through a managed NAT
Gateway — $32/mo plus $0.045/GB. For four boxes that buys nothing a security
group doesn't already give you, and it's half of the "NAT/RDS/ALB burn" that
killed the last attempt. Public subnet + tight security groups is the right
call at this size.

Verify:

```bash
aws ec2 describe-route-tables --route-table-ids "$RTB" \
  --query 'RouteTables[0].Routes'
```

You want two routes: `local` for `10.60.0.0/16`, and `0.0.0.0/0` → your IGW.

---



## Step 2 — key pair

Region-scoped. AWS generates the key and hands you the private half **once** —
there's no retrieving it later.

```bash
aws ec2 create-key-pair --key-name dply-control-plane \
  --query 'KeyMaterial' --output text > ~/.ssh/dply_aws_cp
chmod 400 ~/.ssh/dply_aws_cp
```

Alternatively import the key you already use, which keeps one key across
providers:

```bash
aws ec2 import-key-pair --key-name dply-control-plane \
  --public-key-material "fileb://$HOME/.ssh/id_ed25519.pub"
```

---



## Step 3 — security groups

Vultr has one firewall group with CIDR rules. AWS security groups can name
**another security group** as the source — so "Postgres accepts connections from
the app boxes" is expressed directly, and it keeps working when instances are
replaced and IPs change. This is the one place AWS is genuinely nicer.

Security groups are **stateful** (reply traffic is automatically allowed) and
**allow-only** (no deny rules). Outbound is unrestricted by default, which is
what you want — the control plane SSHes out to customer servers.

```bash
sg() {  # name, description → group id
  aws ec2 create-security-group --group-name "$1" --description "$2" \
    --vpc-id "$VPC" --query 'GroupId' --output text
}

SG_APP=$(sg dply-cp-app  "dply control plane: web + workers")
SG_DB=$(sg  dply-cp-data "dply control plane: postgres + redis")

MYIP=$(curl -s ifconfig.me)/32

# SSH from your IP only — not 0.0.0.0/0
aws ec2 authorize-security-group-ingress --group-id "$SG_APP" \
  --protocol tcp --port 22 --cidr "$MYIP"
aws ec2 authorize-security-group-ingress --group-id "$SG_DB" \
  --protocol tcp --port 22 --cidr "$MYIP"

# HTTP/HTTPS from the world (Cloudflare fronts this)
aws ec2 authorize-security-group-ingress --group-id "$SG_APP" \
  --protocol tcp --port 80  --cidr 0.0.0.0/0
aws ec2 authorize-security-group-ingress --group-id "$SG_APP" \
  --protocol tcp --port 443 --cidr 0.0.0.0/0

# Postgres + Redis: from the app security group ONLY. No CIDRs.
aws ec2 authorize-security-group-ingress --group-id "$SG_DB" \
  --protocol tcp --port 5432 --source-group "$SG_APP"
aws ec2 authorize-security-group-ingress --group-id "$SG_DB" \
  --protocol tcp --port 6379 --source-group "$SG_APP"
```

Note what's *absent*: no rule opens 5432 or 6379 to the internet, even though
these boxes sit in a public subnet with public IPs. The security group is the
boundary, and it's why the NAT Gateway is unnecessary.

---

## Step 3b — break-glass access that survives an IP change

The SSH rules above are pinned to your current IP. That's correct for day-to-day
use and wrong as the *only* way in: home IPs change, and the failure mode is
discovering it during an incident, at 2am, on a control plane that manages every
customer's servers.

**SSM Session Manager** removes the dependency. An agent on the instance dials
*out* to AWS; you connect through the AWS API. No inbound port, no IP allowlist,
no bastion. It's free, every session is logged, and it keeps working when your
address changes or when the SSH rule itself is misconfigured.

Create the instance profile **before** launching, so it can be attached at launch
(attaching later works too, but needs an instance restart to take effect):

```bash
cat > /tmp/ec2-trust.json <<'JSON'
{"Version":"2012-10-17","Statement":[{"Effect":"Allow",
 "Principal":{"Service":"ec2.amazonaws.com"},"Action":"sts:AssumeRole"}]}
JSON

aws iam create-role --role-name dply-cp-ssm \
  --assume-role-policy-document file:///tmp/ec2-trust.json \
  --description "dply control plane: SSM Session Manager break-glass access"
aws iam attach-role-policy --role-name dply-cp-ssm \
  --policy-arn arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore
aws iam create-instance-profile --instance-profile-name dply-cp-ssm
aws iam add-role-to-instance-profile \
  --instance-profile-name dply-cp-ssm --role-name dply-cp-ssm
```

Then every instance launches with `--iam-instance-profile Name=dply-cp-ssm`
(already wired into step 5).

Connecting, once instances are up:

```bash
aws ssm start-session --target i-0abc123...          # shell, no SSH involved
aws ssm describe-instance-information \
  --query 'InstanceInformationList[].[InstanceId,PingStatus]' --output table
```

Or in the console: EC2 → select instance → **Connect** → **Session Manager** tab.

Ubuntu 24.04's AWS AMI ships the SSM agent via snap, so nothing needs installing
— but **verify it registers** after your first launch (`describe-instance-information`
should list the instance as `Online`). An untested break-glass path is not a
break-glass path.

Keep the IP-pinned SSH rule as well. It's faster for routine work and it doesn't
depend on the agent being healthy. Two independent ways in is the point.

---



## Step 4 — find the AMI

An AMI is a machine image, and IDs differ **per region and per architecture**.
Canonical publishes Ubuntu AMI IDs as SSM public parameters, so you look them up
rather than hardcoding. Your codebase already does this —
`[config/services.php:164](../config/services.php#L164)` holds the amd64 parameter.

You need **both** architectures, because the build worker is x86 and everything
else is Graviton:

```bash
# amd64 — for the c6a build worker
AMI_X86=$(aws ssm get-parameter --output text --query 'Parameter.Value' \
  --name /aws/service/canonical/ubuntu/server/24.04/stable/current/amd64/hvm/ebs-gp3/ami-id)

# arm64 — for the t4g / m7g Graviton boxes
AMI_ARM=$(aws ssm get-parameter --output text --query 'Parameter.Value' \
  --name /aws/service/canonical/ubuntu/server/24.04/stable/current/arm64/hvm/ebs-gp3/ami-id)

echo "x86=$AMI_X86  arm=$AMI_ARM"
```

**This is the gotcha that will bite you.** Launch a `c6a.xlarge` with the arm64
AMI and it fails outright; the subtler failure is running Edge builds on
Graviton, where amd64 Docker images fall back to qemu emulation and builds get
several times slower without any error to tell you why.

---



## Step 5 — launch the instances

```bash
launch() {  # name, instance-type, ami, sg, disk-gb → instance id
  aws ec2 run-instances \
    --image-id "$3" --instance-type "$2" \
    --key-name dply-control-plane \
    --iam-instance-profile Name=dply-cp-ssm \
    --subnet-id "$SUBNET" --security-group-ids "$4" \
    --block-device-mappings "[{\"DeviceName\":\"/dev/sda1\",\"Ebs\":{\"VolumeSize\":$5,\"VolumeType\":\"gp3\",\"DeleteOnTermination\":true}}]" \
    --tag-specifications "$(printf "$TAGS" instance "$1")" \
    --query 'Instances[0].InstanceId' --output text
}

WEB=$(     launch dply-web      t4g.medium "$AMI_ARM" "$SG_APP"  40)
WORKER=$(  launch dply-worker-1 c6a.xlarge "$AMI_X86" "$SG_APP" 100)
POSTGRES=$(launch dply-postgres m7g.large  "$AMI_ARM" "$SG_DB"   80)
```

Three things worth understanding in that command:

- `/dev/sda1` is Ubuntu's root device name. Get it wrong and you silently get
a second, unmounted volume alongside the default 8 GB root.
- `DeleteOnTermination: true` — otherwise terminated instances leave orphaned
EBS volumes billing quietly forever. This is the most common way to leak money
on AWS.
- **100 GB on the worker** — Docker images plus the Edge build workspace. The
8 GB default fills within a handful of builds.

Redis rides along on the web box (see the migration doc's cost table) — one
fewer instance, and Redis here is only queues, cache, and the schedule mutex.

Watch them come up:

```bash
aws ec2 describe-instances --instance-ids "$WEB" "$WORKER" "$POSTGRES" \
  --query 'Reservations[].Instances[].{Name:Tags[?Key==`Name`]|[0].Value,State:State.Name,Public:PublicIpAddress,Private:PrivateIpAddress}' \
  --output table
```

---



## Step 6 — Elastic IPs

Two of them, for genuinely different reasons:

- **Web** — the inbound address `dply.io` resolves to.
- **Worker** — the *outbound* identity customer servers see when dply SSHes in.
This is what lands in customer firewall allowlists. It is not the web IP.

```bash
for pair in "WEB:$WEB" "WORKER:$WORKER"; do
  ROLE=${pair%%:*}; ID=${pair##*:}
  ALLOC=$(aws ec2 allocate-address --domain vpc \
    --tag-specifications "$(printf "$TAGS" elastic-ip "dply-$ROLE-eip")" \
    --query 'AllocationId' --output text)
  aws ec2 associate-address --instance-id "$ID" --allocation-id "$ALLOC"
done
```

Elastic IPs are free while attached and **billed when idle** — the opposite of
the intuition. An unattached EIP left over from a torn-down experiment is a
small permanent charge.

---



## Step 7 — verify, then hand off to your own tooling

```bash
ssh -i ~/.ssh/dply_aws_cp ubuntu@<web-eip>
```

Note the user is `ubuntu`, not `root` — already reflected in
`[config/services.php:172](../config/services.php#L172)` (`AWS_EC2_SSH_USER`).
Your bootstrap scripts assume root, so they run under `sudo`.

From here AWS is out of the picture and your existing tooling takes over. The
scripts in `deploy/vultr-control-plane/` (being renamed `deploy/control-plane/`)
are provider-agnostic Ubuntu bash — nothing in them knows or cares which cloud
the box came from:

```bash
DPLY_VPC_CIDR=10.60.0.0/16 bash bootstrap-common.sh
DPLY_PRIVATE_IP=<postgres private ip> bash bootstrap-postgres.sh
DPLY_PRIVATE_IP=<web private ip>      bash bootstrap-redis.sh
DPLY_RUNTIME=web    bash bootstrap-app-layout.sh
DPLY_RUNTIME=worker bash bootstrap-app-layout.sh
```

Then continue at Phase 3 of [CONTROL_PLANE_MIGRATION.md](CONTROL_PLANE_MIGRATION.md).

---



## Step 8 — tear it all down

Run this after the practice pass. Order matters: AWS refuses to delete objects
that other objects still reference, and the error messages are unhelpful about
which dependency is blocking you.

```bash
# Instances first — everything else depends on them
aws ec2 terminate-instances --instance-ids "$WEB" "$WORKER" "$POSTGRES"
aws ec2 wait instance-terminated --instance-ids "$WEB" "$WORKER" "$POSTGRES"

# Elastic IPs — these bill while unattached, so release them
for A in $(aws ec2 describe-addresses --filters Name=tag:dply,Values=control-plane \
             --query 'Addresses[].AllocationId' --output text); do
  aws ec2 release-address --allocation-id "$A"
done

# Then the network, inside out
aws ec2 delete-security-group --group-id "$SG_DB"
aws ec2 delete-security-group --group-id "$SG_APP"
aws ec2 delete-subnet --subnet-id "$SUBNET"
aws ec2 detach-internet-gateway --vpc-id "$VPC" --internet-gateway-id "$IGW"
aws ec2 delete-internet-gateway --internet-gateway-id "$IGW"
aws ec2 delete-route-table --route-table-id "$RTB"
aws ec2 delete-vpc --vpc-id "$VPC"
```

Confirm nothing survived — this is the query that would have caught the last
attempt's leftovers:

```bash
aws ec2 describe-instances --filters Name=tag:dply,Values=control-plane \
  Name=instance-state-name,Values=running,stopped \
  --query 'Reservations[].Instances[].InstanceId'
aws ec2 describe-addresses --query 'Addresses[?AssociationId==null].PublicIp'
aws ec2 describe-volumes --filters Name=status,Values=available \
  --query 'Volumes[].VolumeId'
```

All three should be empty. The second and third are the money leaks: unattached
Elastic IPs and orphaned "available" EBS volumes bill indefinitely and appear
nowhere in the EC2 instance list.

---



## What to take away

- A subnet is public only because its route table points at an internet gateway.
Nothing else makes it so.
- Security groups reference other security groups. Use that instead of CIDRs and
your rules survive instance replacement.
- AMIs are per-region *and* per-architecture. The arm64/amd64 split is the
easiest way to silently wreck Edge build performance.
- The managed conveniences — NAT Gateway, ALB, RDS — are where the money goes,
and at four instances none of them buy you anything.
- Tag everything. Untagged resources are unfindable, and unfindable resources
are what a surprise bill is made of.

