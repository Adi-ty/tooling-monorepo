<?php

declare(strict_types=1);

namespace YourMonorepo\SecondPackage;

/**
 * Added for v0.0.2 to prove a brand-new file reaches the mirror through the split.
 */
final class SecondSplitCheck
{
    public function marker(): string
    {
        return 'second-package split check v0.0.2';
    }
}
