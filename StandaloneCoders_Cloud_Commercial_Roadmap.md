# StandaloneCoders Cloud

## Commercial Coolify-Based SaaS Hosting Platform Roadmap

**Brand:** StandaloneCoders\
**Business model:** SaaS hosting\
**Status:** Planning baseline\
**Date:** 3 November 2026

------------------------------------------------------------------------

## 1. Executive Summary

StandaloneCoders Cloud is a commercial hosting platform built around a
modified Coolify deployment engine and a separate SaaS control plane.

Customers will be able to deploy applications, manage domains and SSL,
provision databases, monitor workloads, manage subscriptions and
optionally use business email hosted through a separately operated Maddy
service.

**Recommended strategy:** Do not rewrite Coolify. Keep it responsible
for deployment operations. Build your own customer dashboard, billing,
provisioning, access control and administration around its supported
APIs.

Launch application hosting first. Introduce email hosting as a
controlled beta after deliverability and abuse controls are proven.

## 2. Product and Target Customers

Target customers: - Freelance developers. - Web development agencies. -
Startups and SaaS companies. - Small and medium businesses. - Agencies
managing multiple client websites.

  -----------------------------------------------------------------------
  Product                 Customer                Revenue model
  ----------------------- ----------------------- -----------------------
  StandaloneCoders Cloud  Developers and startups Monthly hosting
                                                  subscription

  StandaloneCoders Mail   Businesses and agencies Per-mailbox
                                                  subscription

  StandaloneCoders        Businesses needing      Hosting plus managed
  Managed                 operational support     support
  -----------------------------------------------------------------------

## 3. Recommended Technology Stack

  Layer                           Technology
  ------------------------------- ----------------------------
  Customer and admin dashboards   Next.js, React, TypeScript
  SaaS API                        NestJS
  SaaS database                   PostgreSQL
  Queue and cache                 Redis
  Background jobs                 BullMQ
  Deployment engine               Coolify REST API
  Container runtime               Docker
  Email service                   Maddy, separately operated
  Payments                        Razorpay initially
  Object storage                  S3-compatible storage
  Monitoring                      Prometheus and Grafana
  Infrastructure                  Linux VPS providers
  Initial deployment              Docker Compose

Keep the SaaS database separate from Coolify's internal database.
Integrate through documented APIs; do not make the control plane depend
on undocumented internal tables.

### High-level architecture

``` mermaid
flowchart TD
    CUSTOMER[Customer Browser] --> WEB[Next.js Dashboard]
    WEB --> API[NestJS Control Plane]
    API --> DB[(StandaloneCoders PostgreSQL)]
    API --> QUEUE[Redis and BullMQ]
    API <--> PAYMENT[Razorpay]
    QUEUE --> WORKER[Provisioning Workers]
    WORKER --> PROVIDER[VPS Provider]
    WORKER --> COOLIFY[Coolify Instance]
    COOLIFY --> DOCKER[Docker Workloads]
    API --> MAIL[Maddy Mail Service]
    DOCKER --> MONITOR[Monitoring and Backups]
```

### Initial infrastructure layout

  Server or service    Responsibility
  -------------------- ------------------------------------
  Control-plane VPS    NestJS API, PostgreSQL and Redis
  Worker VPS           Provisioning and background jobs
  Customer VPS         Customer application workloads
  Mail VPS             Maddy mail service
  Monitoring/storage   Metrics, logs and off-site backups

For the early beta, prefer one dedicated VPS and one Coolify instance
per customer. This costs more than shared hosting but limits the blast
radius of a tenant compromise. Consider shared infrastructure only with
strong VM isolation, network separation and resource enforcement.

## 4. Product Modules

### Application hosting

-   GitHub and GitLab integration.
-   Dockerfile, Docker Compose and image deployments.
-   Runtime detection.
-   Environment variables and secrets.
-   Build and deployment logs.
-   Deployment history and rollback.

### Database hosting

-   PostgreSQL.
-   MySQL/MariaDB if commercially required.
-   Redis.
-   Database credentials and access controls.
-   Automated backups, restore and export.

### Email hosting --- controlled beta

-   Maddy integration.
-   Domain verification.
-   SMTP and IMAP accounts.
-   SPF, DKIM and DMARC setup.
-   Mailbox quotas.
-   Abuse reporting and suspension.
-   Bounce, complaint and IP reputation monitoring.

Do not promise unrestricted outbound email. Establish sending limits and
acceptable-use rules before launch.

### Infrastructure management

-   VPS provisioning and registration.
-   SSH key management.
-   CPU, RAM, disk and bandwidth monitoring.
-   Firewall management and resource quotas.
-   Server lifecycle operations.

### Domains and SSL

-   Custom domain support.
-   DNS record guidance or provider integration.
-   Domain ownership verification.
-   SSL provisioning and renewal.
-   Subdomain management.

### Billing

-   Monthly and annual subscriptions.
-   Usage metering.
-   Invoices and payment history.
-   Coupons and promotional credits.
-   Failed-payment handling.
-   Cancellation and refunds.

### Monitoring and backups

-   Application uptime.
-   Server CPU, memory and disk.
-   Container health.
-   Backup status and restore testing.
-   Alerts and incident history.

### Super admin

-   Customer and organization management.
-   Plans and subscriptions.
-   Server inventory and resource allocation.
-   Revenue and churn reporting.
-   Support tickets, audit logs and abuse management.

## 5. SaaS Backend Design

### Suggested repository structure

``` text
standalonecoders-cloud/
├── apps/
│   ├── web/                    # Customer dashboard
│   ├── admin/                  # Admin dashboard
│   └── api/                    # NestJS control plane
├── services/
│   ├── provisioning-worker/
│   ├── billing-worker/
│   ├── monitoring-worker/
│   └── notification-worker/
├── packages/
│   ├── database/
│   ├── shared-types/
│   ├── validation/
│   └── coolify-sdk/
├── infrastructure/
│   ├── docker/
│   ├── coolify/
│   ├── maddy/
│   ├── monitoring/
│   └── backups/
└── docs/
    ├── architecture.md
    ├── deployment.md
    ├── security.md
    └── licensing.md
```

### SaaS-owned PostgreSQL tables

  Table               Purpose
  ------------------- ----------------------------------------------
  users               id, name, email, password_hash
  organizations       id, name, owner_id
  memberships         organization_id, user_id, role
  plans               id, name, price, limits
  subscriptions       organization_id, plan_id, status
  payments            subscription_id, amount, gateway_id
  invoices            organization_id, invoice_number, total
  servers             id, provider, ip, status
  coolify_instances   id, server_id, encrypted_api_token
  resources           id, organization_id, coolify_resource_id
  usage_records       resource_id, metric, quantity, recorded_at
  domains             organization_id, domain, verification_status
  deployments         resource_id, deployment_id, status
  backup_jobs         resource_id, status, storage_location
  audit_logs          organization_id, actor_id, action

Use UUIDs, foreign keys, indexes, tenant-scoped queries and PostgreSQL
Row Level Security where appropriate. Encrypt API tokens and secrets
using managed keys or envelope encryption.

### Initial API endpoints

  Method   Endpoint                       Purpose
  -------- ------------------------------ -------------------------
  POST     `/auth/register`               Registration
  POST     `/auth/login`                  Login
  GET      `/organizations`               List organizations
  GET      `/plans`                       List plans
  POST     `/subscriptions`               Create subscription
  POST     `/billing/webhooks/razorpay`   Payment webhook
  POST     `/servers/provision`           Provision server
  GET      `/servers`                     List authorized servers
  POST     `/apps`                        Create application
  POST     `/apps/:id/deploy`             Trigger deployment
  GET      `/apps/:id/logs`               Deployment logs
  POST     `/domains`                     Add domain
  GET      `/usage`                       Usage
  POST     `/backups`                     Request backup

Every endpoint must enforce organization membership, role permissions,
resource ownership, input validation and rate limits. Never expose raw
Coolify credentials to customers.

## 6. Tenant Isolation and Security

Initial isolation model: - One dedicated VPS per customer where
feasible. - One Coolify instance per customer initially. - Unique API
credentials per instance. - Central control plane for accounts and
billing. - Separate customer secrets and network boundaries. - No
customer access to the host Docker socket.

Docker containers alone should not be treated as a sufficient boundary
for hostile multi-tenant workloads. Docker daemon access can provide
host-level control. Use separate VMs or equivalent isolation boundaries
for untrusted customers.

Security requirements: - MFA, secure sessions and password hashing. -
Tenant-scoped RBAC. - API rate limits and validation. - Encryption for
secrets at rest. - SSH key-based access and least privilege. - Firewall
and private networking. - Encrypted off-site backups. - Tamper-evident
audit logs. - Signed and idempotent payment webhooks. - CPU, RAM,
storage, process and bandwidth limits. - Isolated build environments for
untrusted code. - Abuse detection and incident response. - Regular
backup restoration tests.

## 7. VPS Provisioning Workflow

``` mermaid
flowchart TD
    A[Customer registers] --> B[Select plan]
    B --> C[Payment]
    C --> D{Payment verified?}
    D -->|No| E[Pending payment]
    D -->|Yes| F[Create organization]
    F --> G[Provision VPS]
    G --> H[Install and harden Docker]
    H --> I[Install or register Coolify]
    I --> J[Store scoped API credentials]
    J --> K[Apply resource limits]
    K --> L[Enable customer dashboard]
    L --> M[Customer can deploy]
```

Provisioning must be asynchronous and retryable. Use idempotency keys,
job states, timeouts, cleanup/rollback steps and audit logs. Do not run
long provisioning tasks inside HTTP requests.

Potential infrastructure providers to evaluate: - Hetzner Cloud:
https://www.hetzner.com/cloud/ - DigitalOcean:
https://www.digitalocean.com/products/droplets - AWS EC2:
https://aws.amazon.com/ec2/ - Vultr:
https://www.vultr.com/products/cloud-compute/

These are candidates, not confirmed reseller agreements. Verify provider
terms for resale, customer workloads, acceptable use, IP allocation and
support. Compare Indian and nearby regions for latency, price and
reliability.

## 8. Billing and Monetization

Start with Razorpay for India. Consider an international payment
provider later, subject to account eligibility and applicable
requirements.

Documentation: https://razorpay.com/docs/

Payment lifecycle: 1. Customer selects a plan. 2. Backend creates a
pending subscription. 3. Payment or mandate authorization is initiated.
4. Backend verifies signed webhook events. 5. Subscription activates
only after verified payment state. 6. Provisioning worker creates
customer resources. 7. Renewal, failure, cancellation and refund events
update subscription state.

Never activate a subscription solely because the browser reaches a
success URL. Verify signatures, amounts, currency, identifiers and event
state on the backend. Make webhook handling idempotent.

### Suggested initial prices

These are illustrative prices, not validated market prices or final
resource commitments.

  Plan           Indicative price/month Indicative allocation
  ------------ ------------------------ -----------------------
  Developer                        ₹499 1 small app, 1 GB RAM
  Startup                          ₹999 2 apps, 2 GB RAM
  Business                       ₹2,499 5 apps, 4 GB RAM
  Agency                         ₹4,999 10 apps, 8 GB RAM
  Enterprise                     Custom Dedicated resources

Define exact CPU, RAM, storage, bandwidth, backup and support allowances
before publishing plans. Price based on provider costs, expected
utilization, support effort, payment fees, taxes and target gross
margin.

Additional revenue: - Extra storage: illustrative ₹99 per 10 GB/month. -
Additional application: illustrative ₹99/month. - Managed backups:
illustrative ₹199/month. - Business email: illustrative
₹49/mailbox/month. - Dedicated VPS: provider cost plus margin. -
Priority support: illustrative ₹999/month. - Migration: from ₹999. -
Custom deployment: project-based.

Finalize email pricing only after measuring storage, outbound volume, IP
reputation costs, abuse handling and support.

### Example financial scenarios

**Scenario A --- Average revenue model**

-   150 customers.
-   Assumed average revenue per customer: ₹999/month.
-   MRR: ₹1,49,850.

  Expense                            Assumed monthly cost
  -------------------------------- ----------------------
  Infrastructure                                  ₹45,000
  Backup and storage                               ₹5,000
  Monitoring and tools                             ₹4,000
  Support and operations                          ₹15,000
  Payment fees and miscellaneous                   ₹6,000
  Total                                           ₹75,000
  Indicative operating surplus                    ₹74,850

Excludes taxes, founder compensation, marketing, depreciation and other
unlisted costs.

**Scenario B --- Example plan mix**

  Plan          Customers    Price   Monthly revenue
  ----------- ----------- -------- -----------------
  Developer            50     ₹499           ₹24,950
  Startup              60     ₹999           ₹59,940
  Business             25   ₹2,499           ₹62,475
  Agency               15   ₹4,999           ₹74,985
  Total               150      ---         ₹2,22,350

At the same illustrative ₹75,000 expenses, operating surplus is
₹1,47,350 before tax and other omitted costs. These are separate
assumptions, not a forecast.

Track MRR, ARR, CAC, churn, ARPU, gross margin, contribution margin and
server utilization.

## 9. Licensing and Legal Compliance

Verify licences against the exact source code, release, dependency tree,
container images, modifications and distribution method. Do not rely on
a product-level summary alone.

  -----------------------------------------------------------------------
  Component               Commonly identified     Required action
                          licence                 
  ----------------------- ----------------------- -----------------------
  Coolify                 Apache-2.0              Verify release; retain
                                                  notices

  Maddy                   GPL-3.0                 Review distribution and
                                                  modification
                                                  obligations

  PostgreSQL              PostgreSQL Licence      Retain applicable
                                                  notices

  NestJS                  MIT                     Retain applicable
                                                  notices

  Next.js                 MIT                     Retain applicable
                                                  notices

  Docker Engine           Apache-2.0              Verify exact components
                                                  and notices

  Redis                   Version/distribution    Verify exact version
                          dependent               and licence
  -----------------------------------------------------------------------

### Coolify --- Apache 2.0

-   Include the Apache 2.0 licence text.
-   Retain applicable copyright, patent, trademark and attribution
    notices.
-   Mark modified files with prominent modification notices.
-   Include applicable NOTICE content.
-   Do not imply endorsement by the upstream project.
-   Do not use upstream trademarks as your own product identity.

Apache-2.0 generally permits commercial use, modification and
distribution, including keeping independent additions proprietary,
subject to licence conditions.

Licence: https://www.apache.org/licenses/LICENSE-2.0

### Maddy --- GPL-3.0

-   Retain GPL notices and licence text.
-   If distributing modified covered binaries, comply with GPL source
    and corresponding-source requirements.
-   Keep Maddy independently packaged where technically and legally
    appropriate.
-   Review whether an integration creates a combined derivative work.
-   Obtain legal review before distributing a combined product.

GPL-3.0 is not AGPL-3.0. Operating GPL software as a network service
does not by itself impose AGPL's network-interaction source-offer
requirement. Distribution of covered binaries is a separate matter.

### Repository compliance structure

``` text
StandaloneCoders/
├── cloud-platform/             # Proprietary SaaS code
├── coolify-fork/               # Coolify-derived code
├── maddy-integration/          # Integration code
├── maddy-source/               # If source is distributed
├── LICENSE
├── NOTICE
└── THIRD-PARTY-LICENSES/
```

Do not apply one blanket proprietary licence to third-party code that
remains under its original licence. Maintain a software bill of
materials (SBOM), dependency licence inventory and release-by-release
compliance review. Have an IP lawyer review the actual integration and
distribution model.

## 10. India-Specific Business Readiness

Before commercial launch, arrange: 1. Business registration and
applicable tax registrations. 2. GST assessment with a qualified CA for
hosting and digital services. 3. Customer Terms of Service. 4. Privacy
Policy. 5. Acceptable Use Policy. 6. Data Processing Agreement where
appropriate. 7. Refund and cancellation policy. 8. Data retention and
deletion policy. 9. Security incident response procedure. 10. Provider
agreements permitting the intended hosting and resale model.

Assess applicable Indian data-protection requirements, including the
Digital Personal Data Protection Act and rules in force at launch, and
applicable CERT-In directions. Obtain professional advice for the actual
service and customer locations.

## 11. Twelve-Week Development Roadmap

This is a target plan assuming a focused team and reusable existing
Coolify/Maddy work. It is not a guarantee of production readiness.

  -----------------------------------------------------------------------
  Period                  Work                    Deliverable
  ----------------------- ----------------------- -----------------------
  Weeks 1--2              Branding, repository,   Platform foundation
                          auth, organizations,    
                          database, Coolify       
                          adapter                 

  Weeks 3--4              VPS provisioning, SSH,  Server management
                          instance registration,  
                          tenant isolation        

  Weeks 5--6              Git integration,        Application hosting
                          deployments, logs,      
                          domains, SSL            

  Weeks 7--8              Razorpay,               Paid onboarding
                          subscriptions, plans,   
                          webhooks, invoices      

  Weeks 9--10             Backups, monitoring,    Operations baseline
                          usage, admin, support   

  Weeks 11--12            Security audit, load    Commercial beta
                          tests, recovery tests,  
                          pilot customers         
  -----------------------------------------------------------------------

### Launch gates

Do not launch publicly until: - A customer can register, pay and
provision successfully. - Tenant isolation has been independently
tested. - Payment webhooks are verified and idempotent. - Server and
application quotas are enforced. - Backup restoration has been tested. -
Security and abuse-response procedures exist. - Terms, privacy, refund
and licence documentation are published. - Support and incident
escalation are staffed.

## 12. Launch Strategy

**Stage 1 --- Developer Cloud:** Application hosting, deployments,
domains, SSL, basic monitoring and backups.

**Stage 2 --- Business Cloud:** Managed databases, stronger backup
options, usage billing and controlled Maddy email beta.

**Stage 3 --- Agency Cloud:** Multi-project management, client
workspaces, team roles, reseller features and optional white-label
capabilities.

Invite 10--20 developers or agencies for the initial beta. Measure
deployment success, provisioning time, support volume, infrastructure
cost per customer, resource utilization and churn before increasing
marketing spend.

## 13. MVP Scope --- StandaloneCoders Cloud v1.0

Include: - Customer registration and organization dashboard. -
Subscription and payment processing. - Automated VPS provisioning. -
Coolify REST API integration. - Git-based application deployment. -
Domain and SSL management. - PostgreSQL hosting. - Monitoring and
backups. - Super admin dashboard. - Audit logging and tenant access
controls.

Keep Maddy email hosting in a controlled beta until deliverability and
abuse management are proven.

## 14. Final Technical Decisions

  Layer                Selection
  -------------------- -----------------------
  Brand                StandaloneCoders
  Frontend             Next.js + TypeScript
  Backend              NestJS
  Database             PostgreSQL
  Queue                Redis + BullMQ
  Deployment engine    Modified Coolify
  Runtime              Docker
  Email                Maddy
  Payments             Razorpay
  Storage              S3-compatible storage
  Monitoring           Prometheus + Grafana
  Infrastructure       VPS providers
  Initial deployment   Docker Compose

## 15. Official References

-   Coolify documentation: https://coolify.io/docs
-   Coolify API: https://coolify.io/docs/api/overview
-   Coolify repository: https://github.com/coollabsio/coolify
-   Apache License 2.0: https://www.apache.org/licenses/LICENSE-2.0
-   Maddy repository: https://github.com/foxcpp/maddy
-   GNU GPL-3.0: https://www.gnu.org/licenses/gpl-3.0.html
-   Docker security: https://docs.docker.com/engine/security/
-   Razorpay documentation: https://razorpay.com/docs/

------------------------------------------------------------------------

**Planning note:** This document is an architecture and business
roadmap, not a completed implementation or legal opinion. Verify current
upstream licences, provider terms, API behavior, pricing and Indian
regulatory requirements before launch.
