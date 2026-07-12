# Maintaining This Fork

This repository is a maintenance fork of:

https://github.com/Null404-0/SubSieve

The original repository did not include an explicit open-source license when this
fork was prepared. Keep the original commit history and attribution intact. If
you plan to publish releases, container images, packages, or a renamed
distribution, get explicit permission from the original author or ask the
original project to add a license first.

## Suggested Workflow

Keep the original repository as `upstream`:

```bash
git remote -v
```

Add your own repository as `origin`:

```bash
git remote add origin <your-repository-url>
git push -u origin main
```

Sync future upstream changes:

```bash
git fetch upstream
git merge upstream/main
```

## README Attribution

When publishing this fork, add a short note near the top of `README.md`, for
example:

```markdown
> This is a maintenance fork of
> [Null404-0/SubSieve](https://github.com/Null404-0/SubSieve).
```
