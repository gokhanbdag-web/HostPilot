# Domain registrar adapters

HostPilot domain operations use the `DomainRegistrarInterface` contract and `RegistrarFactory`.

## Contract

Each provider adapter implements availability checks, customer/contact creation, registration, nameserver updates, renewals, domain details and asynchronous action status polling. The factory accepts a stable provider key and rejects unknown providers instead of silently falling back.

## Current adapter

- `resellerclub`: the existing ResellerClub API client implements the contract and remains the default provider. Credentials are supplied by the encrypted domain settings.

## Adding a provider

1. Implement `DomainRegistrarInterface` in a provider-specific class.
2. Add the provider key to `RegistrarFactory::supportedProviders()` and route it in `RegistrarFactory::create()`.
3. Keep provider-specific credentials in the encrypted settings store; never commit API secrets.
4. Map asynchronous provider identifiers into the common order/action response contract.
5. Add contract tests for availability, registration, renewals, nameserver changes, errors and status polling before enabling the provider in production.
