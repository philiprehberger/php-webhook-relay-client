# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-06-07

### Added

- `Signer::verify()` and `Signer::sign()` — receiver-side HMAC verifier and a matching signer for the `t=…,v1=…` Stripe/Svix header format. Constant-time comparison, configurable timestamp tolerance.
- `WebhookRelayClient` — small curl-based wrapper covering events, subscriptions, and deliveries. No Guzzle / PSR-18 dependency.
- `WebhookRelayException` — thrown on 4xx/5xx with the RFC 7807 problem payload preserved.
