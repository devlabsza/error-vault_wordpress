# Changelog

All notable changes to ErrorVault WordPress Plugin will be documented in this file.

## [1.3.1] - 2026-01-30

### Added
- **Automatic Update System**: Plugin now checks for updates from GitHub releases
- One-click updates directly from WordPress admin dashboard
- Update notifications when new versions are available

### Changed
- Improved update mechanism for seamless plugin maintenance

---

## [1.3.0] - 2026-01-30

### Added
- **Heartbeat/Ping System**: Prevents sites from stopping reporting to portal
  - Dual cron jobs running every 5 minutes (health check + heartbeat)
  - Non-blocking heartbeat requests for optimal performance
  - Lightweight ping endpoint for minimal overhead
- **Connection Failure Tracking**:
  - Automatic logging of all API connection failures
  - Consecutive failure counter
  - Admin email notifications after 5 consecutive failures (once per day)
  - Failure history stored (last 20 failures)
- **Diagnostics Dashboard**:
  - Connection status indicator (Active/Inactive)
  - Scheduled cron job information
  - Consecutive failures count
  - Recent failure history table
  - Clear failure log button
- **Test Connection Feature**:
  - Quick connectivity test button
  - Blocking requests for testing to detect actual failures
  - Detailed error messages for troubleshooting

### Fixed
- `open_basedir` restriction warning when accessing `/proc/cpuinfo`
- Added `@` error suppression for system file checks with proper fallbacks

### Changed
- Heartbeat implementation uses direct action hook pattern for simplicity
- Improved error handling with specific failure reasons
- Better diagnostics for troubleshooting connection issues

---

## [1.2.2] - 2026-01-28

### Added
- **URL Tracking in Health Monitoring**:
  - Top targeted URLs now included in high request rate alerts
  - Traffic spike alerts show which endpoints are being hit
  - Up to 10 URLs tracked per alert
- **Enhanced Portal Display**:
  - Formatted URL and IP tables in alert details
  - Visual badges showing request counts
  - Truncated URLs with hover tooltips

### Changed
- Health alert data structure now includes `top_urls` field
- Portal view updated with dedicated sections for URLs and IPs

---

## [1.2.0] - 2026-01-27

### Added
- **Comprehensive Server Health Monitoring**:
  - CPU load monitoring with configurable thresholds
  - Memory usage tracking and alerts
  - Disk space monitoring
  - Request rate monitoring for DDoS detection
  - Traffic spike detection
- **Health Alert System**:
  - Configurable alert thresholds for CPU, memory, and traffic
  - Alert cooldown periods to prevent spam
  - Severity levels (warning, critical)
  - Real-time alerts sent to portal
- **Health Reporting**:
  - Periodic health reports every 5 minutes
  - Dashboard showing CPU, memory, disk, and traffic metrics
  - Test health report functionality in admin
- **Portal Integration**:
  - Health alerts table and management
  - Health reports with historical data
  - Visual charts for metrics over time

### Changed
- Added health monitoring settings section to admin page
- Improved admin UI with card-based layout

---

## [1.0.0] - 2025-12-01

### Added
- Initial release of ErrorVault WordPress Plugin
- Real-time error logging to ErrorVault portal
- Automatic error grouping by message and file
- Full stack traces for debugging
- Configurable severity levels (notices, warnings, errors, critical, fatal)
- Batch sending to minimize performance impact
- Exclude patterns to filter out known issues
- Dashboard widget showing error statistics
- Non-blocking API requests
- Connection verification tool
- Test error sending functionality

### Features
- Works with any hosting provider
- Asynchronous error sending
- Minimal performance impact
- Easy configuration via WordPress admin
- API token authentication
- Comprehensive error context (URL, user agent, request method, etc.)
