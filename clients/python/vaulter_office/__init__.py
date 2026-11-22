"""Thin stdlib HTTP client for the Vaulter Office SDK v1 wire protocol."""

from .client import OfficeClient, OfficeProtocolError

__all__ = ["OfficeClient", "OfficeProtocolError"]
