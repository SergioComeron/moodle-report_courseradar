# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0](https://github.com/SergioComeron/moodle-report_courseradar/compare/v1.0.0...v1.1.0) (2026-05-05)


### Features

* add at-risk panel, activity charts, heatmap, completion and sortable tables ([4192a5e](https://github.com/SergioComeron/moodle-report_courseradar/commit/4192a5e3b108c8f8c2ccce027b05c2b0c059f161))
* add module type summary chart and student weekly activity sparklines ([45e5f76](https://github.com/SergioComeron/moodle-report_courseradar/commit/45e5f76efcbf99ffd5c026c47d3e800fee7854ab))
* days-inactive badge per student and top-unseen resources panel ([1639bc2](https://github.com/SergioComeron/moodle-report_courseradar/commit/1639bc246da0f379c828615a7032ae4270765c75))
* initial release of Course Radar v1.0.0 ([b242fd0](https://github.com/SergioComeron/moodle-report_courseradar/commit/b242fd0ebff2c330deff0f4661281c9c567b5925))


### Bug Fixes

* integer axis scale on module type chart; run tests on dev push ([143f698](https://github.com/SergioComeron/moodle-report_courseradar/commit/143f69827037fd1eea7a3f17ffe67faca0dc3219))
* pass Moodle codechecker, add unit tests and dev branch ([367a5e9](https://github.com/SergioComeron/moodle-report_courseradar/commit/367a5e92f79f90ec3c04e6cf3592f23d5a5ef9d8))
* remove duplicate report_courseradar_barclass declaration from index.php ([69a320d](https://github.com/SergioComeron/moodle-report_courseradar/commit/69a320d907d1a43fb8b04f3d1a53f18140bc2a37))
* replace Moodle chart_bar with CSS progress bars for module type summary ([99f3532](https://github.com/SergioComeron/moodle-report_courseradar/commit/99f3532dbe80dc3cc2e31ddc50f966771c5c66a2))
* resolve all Moodle codechecker errors and warnings ([a7ff03f](https://github.com/SergioComeron/moodle-report_courseradar/commit/a7ff03fa9ecd82d510531370fa113b0a58d0caa6))
* resolve all remaining Moodle codechecker errors ([258d228](https://github.com/SergioComeron/moodle-report_courseradar/commit/258d228660e9255f98670620a3aac3dc21f31be6))
* resolve chart/table overlap in module type card ([a63e80e](https://github.com/SergioComeron/moodle-report_courseradar/commit/a63e80eafeeeaabff8fc8cf05f7c12497097cd2d))
* use ASCII lastname in sort test to avoid strcmp byte-ordering issue ([af17eab](https://github.com/SergioComeron/moodle-report_courseradar/commit/af17eabe1c597869f2897897744b52903ba9cf2e))
* use correct Moodle calendar day name strings for heatmap ([c6c1ef5](https://github.com/SergioComeron/moodle-report_courseradar/commit/c6c1ef54fa5cc859079b4f67f15a77c4221fad88))


### Performance

* aggregate chart and heatmap data in SQL instead of PHP ([d535a0e](https://github.com/SergioComeron/moodle-report_courseradar/commit/d535a0e96e145cb50d79b8d54da29881db0f2d62))

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
