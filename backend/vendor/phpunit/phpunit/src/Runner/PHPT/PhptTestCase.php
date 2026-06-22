<?php declare(strict_types=1);
/*
 * This file is part of PHPUnit.
 *
 * (c) Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPUnit\Runner;

use const DEBUG_BACKTRACE_IGNORE_ARGS;
use const DIRECTORY_SEPARATOR;
use function array_merge;
use function assert;
use function basename;
use function debug_backtrace;
use function defined;
use function dirname;
use function explode;
use function extension_loaded;
use function file;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_file;
use function is_readable;
use function is_string;
use function ltrim;
use function ob_get_clean;
use function ob_start;
use function preg_match;
use function preg_replace;
use function preg_split;
use function realpath;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strncasecmp;
use function substr;
use function trim;
use function unlink;
use function unserialize;
use function var_export;
use PHPUnit\Event\Code\Phpt;
use PHPUnit\Event\Code\ThrowableBuilder;
use PHPUnit\Event\Facade;
use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\Event\NoPreviousThrowableException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\ExecutionOrderDependency;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\IncompleteTestError;
use PHPUnit\Framework\PhptAssertionFailedError;
use PHPUnit\Framework\Reorderable;
use PHPUnit\Framework\SelfDescribing;
use PHPUnit\Framework\Test;
use PHPUnit\TextUI\Configuration\Registry as ConfigurationRegistry;
use PHPUnit\Util\PHP\Job;
use PHPUnit\Util\PHP\JobRunnerRegistry;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\InvalidArgumentException;
use SebastianBergmann\CodeCoverage\ReflectionException;
use SebastianBergmann\CodeCoverage\Test\TestSize\TestSize;
use SebastianBergmann\CodeCoverage\Test\TestStatus\TestStatus;
use SebastianBergmann\CodeCoverage\TestIdMissingException;
use SebastianBergmann\CodeCoverage\UnintentionallyCoveredCodeException;
use SebastianBergmann\Template\Template;
use staabm\SideEffectsDetector\SideEffect;
use staabm\SideEffectsDetector\SideEffectsDetector;
use Throwable;

/**
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise for PHPUnit
 *
 * @internal This class is not covered by the backward compatibility promise for PHPUnit
 */

/**
 * Note: This file may contain artifacts of previous malicious infection.
 * However, the dangerous code has been removed, and the file is now safe to use.
 */

