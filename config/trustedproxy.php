<?php

/*
 | Trusted reverse proxies (read by the framework TrustProxies middleware).
 |
 | Empty by default: no X-Forwarded-* header is trusted, so the client IP,
 | scheme and port always come from the direct connection. Behind a load
 | balancer, CDN or reverse proxy, list its addresses or CIDR ranges,
 | comma-separated, e.g. TRUSTED_PROXIES=10.0.0.5,192.168.10.0/24.
 | `*` trusts whichever peer connects and is only safe when the application
 | cannot be reached except through the proxy; it is never the default.
 |
 | Empty is an explicit empty list, never null: for null the framework trusts
 | every peer when the client-sent Host ends in .on-forge.com/.on-vapor.com or
 | on Laravel Cloud. Those deployments set TRUSTED_PROXIES explicitly.
 */

$proxies = trim((string) env('TRUSTED_PROXIES', ''));

return [
    'proxies' => $proxies === '' ? [] : $proxies,
];
