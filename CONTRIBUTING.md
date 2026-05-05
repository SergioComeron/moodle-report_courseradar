# Contributing to Course Radar

Thank you for considering a contribution!

## Reporting bugs

Open an [issue](../../issues/new) and include:
- Moodle version and database type
- PHP version
- Steps to reproduce
- Expected vs actual behaviour

## Submitting a pull request

1. Fork the repository and create a branch from `main`.
2. Make your changes following the [Moodle Coding Style](https://moodledev.io/general/development/policies/codingstyle).
3. Run the CI checks locally if possible (see below).
4. Open a pull request against `main` with a clear description of the change.

## Running CI locally

Install [moodle-plugin-ci](https://moodlehq.github.io/moodle-plugin-ci/) and run:

```bash
moodle-plugin-ci phplint
moodle-plugin-ci codechecker
moodle-plugin-ci validate
```

## Coding standards

This plugin follows the [Moodle Coding Guidelines](https://moodledev.io/general/development/policies/codingstyle).  
All PHP files must pass `moodle-plugin-ci phplint` and `codechecker` without errors.

## License

By contributing you agree that your code will be released under the [GPL v3](LICENSE) licence.
