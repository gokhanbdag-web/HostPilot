# Deployment automation

HostPilot production deployment is triggered by GitHub push events to the `main` branch.

The server-side webhook validates GitHub's `X-Hub-Signature-256` HMAC before starting the deployment script. The deployment fetches the latest `main` commit, runs PHP syntax checks, creates a code backup, and synchronizes application files while preserving the live `storage/` directory and webhook receiver.

Webhook secrets and server credentials must remain outside the repository and must never be committed.
