<?php

declare(strict_types=1);

namespace YourMonorepo\FirstPackage;

/**
 * Added for v0.0.2 to prove a brand-new file reaches the mirror through the split.
 */
final class FirstSplitCheck
{
    public function marker(): string
    {
        return 'first-package split check v0.0.2';
    }
}
