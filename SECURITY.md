# Security Policy

## Reporting a vulnerability

Email **laurowguedes@gmail.com** rather than opening a public issue. You will get
an acknowledgement within a few days.

## Before you report

This package is destructive by design. `demo:reset` drops every table — that is
its documented purpose, not a vulnerability. What *is* worth reporting:

- a way past any of the six reset barriers without editing configuration
  (see [docs/security.md](docs/security.md))
- a way to read the published credentials from an installation where
  `DEMO_MODE` is off
- a published password reaching a log, an event payload, an exception message or
  an HTTP response that documents it as redacted
- `demo:doctor` reporting no errors on a configuration that would destroy
  production data or serve credentials over HTTP

## Supported versions

The latest minor release receives security fixes.
