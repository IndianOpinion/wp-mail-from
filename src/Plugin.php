<?php

declare(strict_types=1);

namespace IndianOpinion\MailFrom;

use IndianOpinion\MailFrom\Admin\GeneralSettings;
use IndianOpinion\MailFrom\Admin\NetworkSettings;
use IndianOpinion\MailFrom\Admin\Notices;
use IndianOpinion\MailFrom\Cli\Command;

/**
 * Wires the services together and registers hooks. Loading costs hook
 * registration only: no option reads, no file I/O (PRD section 9).
 */
final class Plugin
{
    public const VERSION = '0.1.0';

    private static ?self $instance = null;

    /** @var list<string> */
    private static array $duplicates = [];

    public readonly Options $options;
    public readonly Validator $validator;
    public readonly Logger $logger;
    public readonly Resolver $resolver;
    public readonly SmtpDetector $detector;
    public readonly MailHandler $handler;
    public readonly Settings $settings;
    public readonly Report $report;

    public function __construct(
        private readonly string $file,
        ?Options $options = null,
        ?Logger $logger = null,
        ?Validator $validator = null,
        ?SmtpDetector $detector = null
    ) {
        $this->options = $options ?? new Options();
        $this->validator = $validator ?? new Validator();
        $this->logger = $logger ?? new Logger();
        $this->detector = $detector ?? new SmtpDetector();
        $this->resolver = new Resolver($this->options, $this->validator, $this->logger);
        $this->handler = new MailHandler(
            $this->resolver,
            new HeaderParser(),
            new DecisionEngine($this->validator),
            $this->detector,
            $this->validator,
            $this->logger
        );
        $this->settings = new Settings($this->options, $this->validator);
        $this->report = new Report($this->resolver, $this->detector);
    }

    /**
     * Boot the first copy. A second copy (must-use and normal plugin at
     * once) registers nothing and is reported (C51).
     */
    public static function boot(string $file): ?self
    {
        if (self::$instance !== null) {
            self::noteDuplicate($file);

            return null;
        }
        self::$instance = new self($file);
        self::$instance->register();

        return self::$instance;
    }

    /**
     * Install a prepared instance (tests, or a custom bootstrap).
     */
    public static function install(self $plugin): self
    {
        self::$instance = $plugin;
        $plugin->register();

        return $plugin;
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    public static function noteDuplicate(string $file): void
    {
        if (!in_array($file, self::$duplicates, true)) {
            self::$duplicates[] = $file;
        }
    }

    /**
     * @return list<string>
     */
    public static function duplicates(): array
    {
        return self::$duplicates;
    }

    public static function activeFile(): string
    {
        return self::$instance !== null ? self::$instance->file : '';
    }

    /**
     * Forget the booted instance. For tests only.
     */
    public static function resetForTests(): void
    {
        self::$instance = null;
        self::$duplicates = [];
    }

    /**
     * The priority of the From filters (C63): WPMF_FROM_PRIORITY when it is
     * an integer, else 5.
     */
    public function fromPriority(): int
    {
        $value = $this->options->rawConstant('WPMF_FROM_PRIORITY');
        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            return (int) $value;
        }

        return MailHandler::PRIORITY_FROM;
    }

    public function register(): void
    {
        $this->handler->register($this->fromPriority());

        foreach (['added_option', 'updated_option', 'deleted_option'] as $hook) {
            add_action($hook, [$this->resolver, 'onOptionChange'], 10, 1);
        }
        foreach (['add_site_option_', 'update_site_option_', 'delete_site_option_'] as $prefix) {
            add_action($prefix . Options::NETWORK_OPTION, [$this->resolver, 'flush'], 10, 0);
        }

        add_filter('site_status_tests', [new SiteHealth($this->report), 'register']);

        if (is_admin()) {
            $general = new GeneralSettings($this->options, $this->settings, $this->resolver);
            $network = new NetworkSettings($this->options, $this->settings);
            $notices = new Notices($this->report);
            add_action('admin_init', [$general, 'register']);
            add_action('admin_init', static function () use ($notices): void {
                $redirect = $notices->handleDismiss();
                if ($redirect !== null) {
                    wp_safe_redirect($redirect);
                    exit;
                }
            });
            add_action('admin_notices', [$notices, 'render']);
            add_action('network_admin_notices', [$notices, 'render']);
            add_action('network_admin_notices', [$network, 'renderErrors']);
            add_action('wpmu_options', [$network, 'render']);
            add_action('update_wpmu_options', static function () use ($network): void {
                $network->save();
            });
        }

        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command(
                'mail-from',
                new Command($this->report, $this->resolver, $this->handler, $this->settings)
            );
        }
    }
}
