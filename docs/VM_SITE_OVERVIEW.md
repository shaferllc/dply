# VM site overview

dply **BYO VM sites** run on your servers with Git deploys, managed webservers, and SSH-backed tooling. The workspace sidebar is grouped by job — **Networking**, **Deploy**, **Runtime**, etc.

## What VM sites are for

Use VM sites for **PHP**, **Rails**, **WordPress**, **static**, and **Docker-on-VM** apps. For CDN-first static/SSG, use **Edge**. For managed containers without SSH, use **Cloud**.

## Section map

**General** — **General** (live URL, deploy), **Settings** (name, strategy, suspend)

**Networking** — **Routing**, **DNS**, **Certificates**, **Web server config**, **Caching**, **CDN / Edge**

**Deploy** — **Deployments** (run + history), **Repository** (Git, branch, webhooks), **Pipeline** (steps, hooks, rollout)

**Runtime** — **Runtime**, **System user**, **Laravel / Rails / WordPress** (when detected), **Environment**

**Observability** — **Logs**, **Notifications**, **Monitor**

**Background** — **Cron jobs**, **Schedule**, **Daemons** (queue workers + other Supervisor programs), **Backups** (links to server)

**Access** — **Authentication** (HTTP basic auth)

**Danger** — **Danger zone** (delete site)

Each section has a matching guide in **Documentation → Site workspace guides** (sidebar panel on every workspace page).

## Site vs server

| **Site workspace** | **Server workspace** |
|--------------------|------------------------|
| One app — deploy, domains, env | Whole host — firewall, webserver |
| **Deploy** from Git | **Sites** lists all apps |

**Schedule** and **Backups** open server pages with this site in context.

## Quick "where do I…?"

| I want to… | Go to |
|------------|--------|
| Change primary domain | **Routing → Domains** |
| Deploy now / view history | **Deployments** |
| Zero-downtime, hooks, pipeline steps | **Pipeline** |
| Git URL, branch, deploy key | **Repository** |
| Edit nginx vhost | **Web server config** |
| Add env vars | **Environment** |
| Lock staging | **Authentication** |

Each section has a guide under **Documentation → BYO VM guides**.
