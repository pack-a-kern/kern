"""TaxOS România — processing plane.

FastAPI service shared by the python-api and python-worker containers
(`BLUEPRINT.md` §1.2, §3.1). This service never receives Laravel database
credentials and never writes to Laravel business tables (§3.3); it is
reached through an authenticated internal API and exchanges data with
Laravel via signed job payloads and object-storage references.
"""
