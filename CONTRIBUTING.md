# Contributing

Contributions are **welcome** and will be fully **credited**.

## Etiquette

This project is open source, and as such, the maintainers give their free time to build and maintain the source code held within. They make the code freely available in the hope that it will be of use to other developers. It would be extremely unfair for them to suffer abuse or anger for their hard work.

Please be considerate towards maintainers when raising issues or presenting pull requests. Let's show the world that developers are civilized and selfless people.

It's the duty of the maintainer to ensure that all submissions to the project are of sufficient quality to benefit the project. Many developers have different skillsets, strengths, and weaknesses. Respect the maintainer's decision, and do not be upset or abusive if your submission is not used.

## Viability

When requesting or submitting new features, first consider whether it might be useful to others. Open source projects are used by many developers, who may have entirely different needs to your own. Think about whether or not your feature is likely to be used by other users of the project.

## Procedure

### Before Filing an Issue

- Search existing issues to avoid duplicates
- Check the [documentation](README.md) to ensure it's not a usage question
- Provide a clear title and description
- Include steps to reproduce the issue
- Specify your environment (PHP version, Docker version)
- Include relevant code samples and full error messages

### Before Submitting a Pull Request

- **Discuss non-trivial changes first** by opening an issue
- **Fork the repository** and create a feature branch from `main`
- **Follow all requirements** listed below
- **Write tests** for your changes
- **Update documentation** if behavior changes
- **Run code style checks** with `vendor/bin/php-cs-fixer fix`
- **Ensure all tests pass** with `vendor/bin/phpunit`
- **Write clear commit messages** that explain what and why

## Requirements

- **[PSR-12 Coding Standard](https://www.php-fig.org/psr/psr-12/)** - Run `vendor/bin/php-cs-fixer fix` to automatically fix code style issues.
- **Add tests** - Your patch won't be accepted if it doesn't have tests. All tests must use [PHPUnit](https://phpunit.de/).
- **Document any change in behavior** - Make sure the `README.md` and any other relevant documentation are kept up-to-date.
- **Consider our release cycle** - We follow [SemVer v2.0.0](https://semver.org/). Randomly breaking public APIs is not an option.
- **One pull request per feature** - If you want to do more than one thing, send multiple pull requests.
- **Send coherent history** - Make sure each individual commit in your pull request is meaningful. If you had to make multiple intermediate commits while developing, please [squash them](https://www.git-scm.com/book/en/v2/Git-Tools-Rewriting-History#Changing-Multiple-Commit-Messages) before submitting.

## Running Tests

> **Note:** Tests require Docker and Docker Compose to be installed and running.

```bash
vendor/bin/phpunit
```

## Code Style

This package follows PSR-12 coding standard. Run PHP CS Fixer before submitting:

```bash
vendor/bin/php-cs-fixer fix
```
