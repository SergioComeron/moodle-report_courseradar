# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.6.0](https://github.com/SergioComeron/moodle-report_courseradar/compare/v1.5.0...v1.6.0) (2026-06-30)


### Features

* add student self-view with personal metrics and class comparison ([513c33f](https://github.com/SergioComeron/moodle-report_courseradar/commit/513c33f2d20b84345f369e067ba638a8877fccbc))
* explain the engagement score with its formula in the student comparison card ([9ab1149](https://github.com/SergioComeron/moodle-report_courseradar/commit/9ab11490cf4627b5e05cabdf6a68b311d9777b78))


### Bug Fixes

* localize activity type names instead of showing raw module names ([b263b3a](https://github.com/SergioComeron/moodle-report_courseradar/commit/b263b3ae0ff86b501e3f51a0f15fe9924977e86f))
* localize chart month labels that were always shown in English ([a32c251](https://github.com/SergioComeron/moodle-report_courseradar/commit/a32c251111384bf380aa2c3e4ffbbd99b1f48d9a))
* prevent activity and heatmap cards from overlapping when the right block drawer is open ([2840f3a](https://github.com/SergioComeron/moodle-report_courseradar/commit/2840f3af3fbe31783e0f1ae67d4f65152895b5c4))
* use phpdoc [@covers](https://github.com/covers) tags so coverage is recognised on Moodle 4.5 ([684a6a2](https://github.com/SergioComeron/moodle-report_courseradar/commit/684a6a2fafde9a974f85ee64bd390bd82cfa021d))

## [1.5.0](https://github.com/SergioComeron/moodle-report_courseradar/compare/v1.4.3...v1.5.0) (2026-06-15)


### Features

* always show analysed period below the report subtitle ([3ab4689](https://github.com/SergioComeron/moodle-report_courseradar/commit/3ab4689787745a094fea6330c983f8af5571935f))


### Bug Fixes

* exclude subsection modules from all reports ([f22900a](https://github.com/SergioComeron/moodle-report_courseradar/commit/f22900a90b641fa7d12cd9e0b5ad26de6ffbf489))
* top unseen shows real top 10 per type filter, not top 10 overall ([85ce18b](https://github.com/SergioComeron/moodle-report_courseradar/commit/85ce18bcc9a5ba294399d25e49beb424840d7b0d))

## [1.4.3](https://github.com/SergioComeron/moodle-report_courseradar/compare/v1.4.2...v1.4.3) (2026-05-13)


### Bug Fixes

* correct lang string ordering (adjustperiod, scatter vs scoredist) ([d2b4cf4](https://github.com/SergioComeron/moodle-report_courseradar/commit/d2b4cf471ffe5900d2ab97d35ff9a8482eccd631))

## [1.4.2](https://github.com/SergioComeron/moodle-report_courseradar/compare/v1.4.1...v1.4.2) (2026-05-13)


### Bug Fixes

* one argument per line in test function calls (PSR2) ([7dea3de](https://github.com/SergioComeron/moodle-report_courseradar/commit/7dea3de0c0457a73ec1f38b6cf62b11e8428e228))

## [1.4.1](https://github.com/SergioComeron/moodle-report_courseradar/compare/v1.4.0...v1.4.1) (2026-05-13)


### Bug Fixes

* one argument per line in multi-line function calls (PSR2) ([a05cb27](https://github.com/SergioComeron/moodle-report_courseradar/commit/a05cb27144ade023c3ac136ec9d5df077bd039a5))

## [1.4.0](https://github.com/SergioComeron/moodle-report_courseradar/compare/v1.3.2...v1.4.0) (2026-05-13)


### Features

* add scatter plot and score distribution histogram ([f49c234](https://github.com/SergioComeron/moodle-report_courseradar/commit/f49c234d06dde1ad7c8f0d751cc73d15166cef89))
* make heatmap student badges link to their course profile ([2ee01db](https://github.com/SergioComeron/moodle-report_courseradar/commit/2ee01db99d78bbbbd12d6dc3494b67def99ca832))
* show active students on heatmap cell click ([6fee7e2](https://github.com/SergioComeron/moodle-report_courseradar/commit/6fee7e2094a7eede429a888d5cea9496734b851e))


### Bug Fixes

* exclude label modules from all reports ([30f6b19](https://github.com/SergioComeron/moodle-report_courseradar/commit/30f6b19c1d5f8f36f35d0e818b1f4eac81be5c2c))


### Code Refactor

* extract engagement score logic to locallib with unit tests ([73e4b98](https://github.com/SergioComeron/moodle-report_courseradar/commit/73e4b9870ca6706653ab8dbe89322f9ce60f3416))

## [1.3.2](https://github.com/SergioComeron/moodle-report_courseradar/compare/v1.3.1...v1.3.2) (2026-05-08)


### Documentation

* rewrite README to reflect all new features and tab layout ([6fafb2f](https://github.com/SergioComeron/moodle-report_courseradar/commit/6fafb2fc6cc4c94ba149a83425cf730d823db93e))

## [1.3.1](https://github.com/SergioComeron/moodle-report_courseradar/compare/v1.3.0...v1.3.1) (2026-05-08)


### Bug Fixes

* move resetsort before riskscore in lang/en to fix CI codechecker ([a65ca47](https://github.com/SergioComeron/moodle-report_courseradar/commit/a65ca4735bda4c919ac18b56945d92329dd7a6bf))

## [1.3.0](https://github.com/SergioComeron/moodle-report_courseradar/compare/v1.2.0...v1.3.0) (2026-05-08)


### Features

* add at-risk messaging form and per-student completion progress ([a7410b8](https://github.com/SergioComeron/moodle-report_courseradar/commit/a7410b82e92a071f60fcbe32fdcae4ef55af0f09))
* add engagement distribution chart, risk score column and weekly trend in student detail ([2ea9972](https://github.com/SergioComeron/moodle-report_courseradar/commit/2ea9972569a865df358737dc310b5fde926cf481))
* add last course visit column to Student Engagement table ([0ce856d](https://github.com/SergioComeron/moodle-report_courseradar/commit/0ce856d0f1dd985b4895b67a5047eb6cfab5e97a))
* add week-over-week trend indicators to KPI cards ([61afa00](https://github.com/SergioComeron/moodle-report_courseradar/commit/61afa00d8d61ce541f241e019981c29734205140))
* persist UI state (tab, filters, show-hidden) in localStorage per course ([e5a0163](https://github.com/SergioComeron/moodle-report_courseradar/commit/e5a0163fc49779c54f2a0f9f811fe7f9a31a0db8))
* reorganise report into tabs and add descriptive text to all sections ([0b2fce8](https://github.com/SergioComeron/moodle-report_courseradar/commit/0b2fce86680fedf84b7b00e914b046bc24d06976))


### Bug Fixes

* guard bootstrap.Tooltip call behind existence check ([6db569d](https://github.com/SergioComeron/moodle-report_courseradar/commit/6db569dbd22342898f9471077fdaff3baaa2dba7))
* replace Bootstrap collapse with JS toggle for at-risk message form ([08f978c](https://github.com/SergioComeron/moodle-report_courseradar/commit/08f978ca89177833c6aeae8876c6455ec16eaefb))
* tighten detail column layout and add green completion to legend ([60b437b](https://github.com/SergioComeron/moodle-report_courseradar/commit/60b437bdf5e0eec83dce342544d23d2e6c70dc07))
* use natural-width columns in detail grid and correct string key ([51161c8](https://github.com/SergioComeron/moodle-report_courseradar/commit/51161c8f873d9c0cb8b6c15f8acffe31d7799986))


### Code Refactor

* compact icon-only grid for student detail activity view ([17bef94](https://github.com/SergioComeron/moodle-report_courseradar/commit/17bef944381e107007c106538443ad1d9ea3131e))

## [1.2.0](https://github.com/SergioComeron/moodle-report_courseradar/compare/v1.1.0...v1.2.0) (2026-05-05)


### Features

* add client-side resource type filter above resources table ([e8efa3d](https://github.com/SergioComeron/moodle-report_courseradar/commit/e8efa3db534169db24ab3af828883ebce2798c68))
* add column description subtitles and sparkline Bootstrap tooltips ([abb9368](https://github.com/SergioComeron/moodle-report_courseradar/commit/abb93688a169ed274ae6b56143ae2b3acc7c07b2))
* add course-level visit tracking as summary card and student column ([ba2e7ea](https://github.com/SergioComeron/moodle-report_courseradar/commit/ba2e7ea271bb9047cac0201c7fc07f60bd12f9bd))
* add real-time student search input to engagement table ([e22a1d5](https://github.com/SergioComeron/moodle-report_courseradar/commit/e22a1d5f62a43b0cbaa169815866b67b07c30864))
* add resource type filter to least visited resources card ([8fdf57b](https://github.com/SergioComeron/moodle-report_courseradar/commit/8fdf57b8fbaf79608af9fcc3875b5017cb862c5e))
* add toggle to show/hide hidden activities in resources table ([3800ec7](https://github.com/SergioComeron/moodle-report_courseradar/commit/3800ec7df86b414950521d2d8ab92a2fb3ec4f19))


### Bug Fixes

* add text-white to at-risk badges to prevent red-on-red cascade ([baa46ac](https://github.com/SergioComeron/moodle-report_courseradar/commit/baa46ac369be66d35a1fb263e4836c7ab2cf88c6))
* checkout to 'courseradar' folder so ZIP extracts with correct name ([9f538e4](https://github.com/SergioComeron/moodle-report_courseradar/commit/9f538e41efa9f796967354e349466fd33355073a))
* correct lang ES string ordering and add --repo to gh pr merge ([649a6b3](https://github.com/SergioComeron/moodle-report_courseradar/commit/649a6b3e83b04076b5970b77e1359377b92f1d1f))
* increase gap between resource type filter buttons ([f4aae5c](https://github.com/SergioComeron/moodle-report_courseradar/commit/f4aae5c04b8891fb302d38d1e3d4494f939917dd))
* remove hover background on sortable column headers ([e6f9866](https://github.com/SergioComeron/moodle-report_courseradar/commit/e6f98662fc65b7541ef90668271a8ccc2d72667e))
* reset Bootstrap CSS var on thead hover to eliminate gray tint ([3338558](https://github.com/SergioComeron/moodle-report_courseradar/commit/33385581235e96caaa49911c09e61c1f83850b95))
* restore student search threshold to &gt; 5 students ([bc1cbb1](https://github.com/SergioComeron/moodle-report_courseradar/commit/bc1cbb1237071830265b824c264573b15d02943a))
* show student search input regardless of student count ([83eb881](https://github.com/SergioComeron/moodle-report_courseradar/commit/83eb881cc5f8beaf01dc34e6838d85a8e39cc6dc))
* suppress Bootstrap hover background on dark table headers ([99a876f](https://github.com/SergioComeron/moodle-report_courseradar/commit/99a876f1fb01c5028fb9a4ce93f948c6d1e86cdb))
* switch table headers from table-dark to table-light for subtler look ([4688071](https://github.com/SergioComeron/moodle-report_courseradar/commit/46880718b22f573e1c9625661fc50b3c60100eae))
* use explicit margin on type filter buttons instead of gap ([aa05cf5](https://github.com/SergioComeron/moodle-report_courseradar/commit/aa05cf5e24396390c597305f89fb54f19f8a527c))

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
