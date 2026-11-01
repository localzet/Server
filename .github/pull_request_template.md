## Summary

## Compatibility and behavior
- [ ] Public PHP API and wire behavior stay compatible, or the breaking change is documented.
- [ ] PHP 8.1 compatibility is preserved or the supported floor is intentionally updated.
- [ ] Optional integrations remain optional, or new requirements are documented.

## Security and operations
- [ ] Input validation, resource cleanup, timeouts and shutdown behavior were considered.
- [ ] No secrets, personal data, generated dependencies or unrelated changes are included.

## Verification
List commands and results. Note any platform or optional extension checks not run.

- [ ] `composer validate --strict`
- [ ] `composer run lint`
- [ ] Relevant smoke, integration or fuzz checks
