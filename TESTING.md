# CF7 Submission Guard v0.1.0 — smoke test

Use a Contact Form 7 form whose tag names match the plugin settings.

1. Valid submission after the configured minimum time should pass.
2. Name longer than the configured maximum should be rejected.
3. Message shorter than the configured minimum should be rejected.
4. URL forms such as `https://example.com`, `www.example.com`, and `example.com` in protected fields should be rejected.
5. `@` and email-like text in protected fields should be rejected when enabled.
6. HTML tags should be rejected when enabled.
7. An email from a blocked domain or its subdomain should be rejected.
8. A country value not present in the configured allowlist (or the CF7 select values when the allowlist is empty) should be rejected.
9. A form submitted before the minimum completion time should be rejected, including if the timing signature is missing or altered.
10. After the configured number of attempts from one IP within the window, further attempts should be rejected.
11. Duplicate message blocking should reject the same message from the same IP within its configured window when enabled.
12. Required consent should reject an empty configured consent field when enabled.
13. Monitor mode should log rule matches without invalidating the CF7 submission.
14. Logs must not contain the submitted name, email address, or message body.
