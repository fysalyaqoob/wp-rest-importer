# Contributing to WP REST Importer

## Reporting Issues
https://github.com/fysalyaqoob/wp-rest-importer/issues

## Pull Requests
1. Fork the repository
2. Create feature branch: git checkout -b feature/your-feature
3. Commit changes: git commit -m "feat: description"
4. Push branch: git push origin feature/your-feature
5. Open Pull Request to main

## Commit Message Format
- feat: new feature
- fix: bug fix
- chore: maintenance, version bumps
- docs: documentation only
- style: formatting, no logic change
- refactor: code change with no feature/fix

## Code Standards
- PHP 7.4+ compatible
- WordPress Coding Standards
- No external dependencies
- All functions prefixed wpresti_
- All hooks prefixed wpresti_
- Nonce verification on all AJAX calls
- Capability checks: manage_options
