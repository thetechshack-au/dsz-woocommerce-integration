# Changelog
All notable changes to the DSZ WooCommerce Product Importer will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.5.0] - 2024
### Added
- WebP image conversion with automatic JPEG fallback
- Configurable image settings in admin panel
- File integrity checks for image processing
- Comprehensive developer documentation
- Image optimization settings:
  - Configurable dimensions
  - Quality settings for WebP and JPEG
  - Processing options (WebP preference, original file backup)
  - Storage organization options

### Changed
- Improved error handling and recovery in image processing
- Enhanced logging with better context
- Removed version timestamp display from admin notices
- Optimized memory usage in image handling

### Fixed
- Image truncation issues during processing
- Memory leaks in image handling
- Temporary file cleanup

## [1.4.0] - 2024
### Added
- Initial release of modular structure
- Product mapping functionality
- Order synchronization
- Shipping zone management
- Category management
- Basic image handling

### Changed
- Restructured codebase for better maintainability
- Improved error handling
- Enhanced logging system

## Notes
- The changelog is maintained manually
- Dates are in YYYY-MM-DD format when specific dates are known
- Version numbers follow semantic versioning (MAJOR.MINOR.PATCH)
  - MAJOR version for incompatible API changes
  - MINOR version for added functionality in a backward compatible manner
  - PATCH version for backward compatible bug fixes
