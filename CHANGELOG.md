# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] — 2025-05-05

### Added
- Initial release.
- Summary dashboard cards: total resources, total interactions, average engagement, most-visited resource.
- Resource & activity table grouped by course section with per-item metrics.
- Expandable detail panel per resource showing who has and hasn't viewed it, with view count and last-access date.
- Student engagement table with per-student coverage bar and expandable resource-badge breakdown.
- Date range filter with reset button; defaults to course start date → today.
- Role-aware student detection: users with `report/courseradar:view` capability are excluded from interaction counts.
- English and Spanish language packs.
- Capability `report/courseradar:view` granted by default to `teacher`, `editingteacher`, and `manager` archetypes.
- Navigation hook that adds the report link under course Reports menu.

[Unreleased]: https://github.com/SergioComeron/moodle-report_courseradar/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/SergioComeron/moodle-report_courseradar/releases/tag/v1.0.0
