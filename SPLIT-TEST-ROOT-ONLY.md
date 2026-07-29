# Root-only marker

This file lives at the monorepo root, outside `composer-packages/`.

It must **never** appear in `Adi-ty/first-package` or `Adi-ty/second-package`. If it does, the
subtree split is carrying monorepo-root content into the mirrors.
