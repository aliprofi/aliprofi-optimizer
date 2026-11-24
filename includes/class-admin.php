<?php
/**
 * Админ-интерфейс: меню «Оптимизатор», вкладки, обслуживание и уведомления.
 *
 * @package Aliprofi_Optimizer
 */

namespace AliProfi\Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Интерфейс администратора.
 */
final class Admin {

	/**
	 * Хуки администратора и админ-бара.
	 */
	public function run(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_bar_menu', array( $this, 'bar' ), 100 );
		add_action( 'admin_post_aliprofi_optimizer_purge_all', array( $this, 'handle_purge_all' ) );
		add_action( 'admin_post_aliprofi_optimizer_purge_page', array( $this, 'handle_purge_page' ) );
		add_action( 'admin_post_aliprofi_optimizer_clear_fonts', array( $this, 'handle_clear_fonts' ) );
		add_action( 'admin_post_aliprofi_optimizer_db_cleanup', array( $this, 'handle_db_cleanup' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'wp_footer', array( $this, 'toast' ), 100 );
		add_filter( 'plugin_action_links_' . plugin_basename( ALIPROFI_OPTIMIZER_FILE ), array( $this, 'links' ) );
	}

	/**
	 * Пункт меню «Оптимизатор».
	 */
	public function menu(): void {
		add_menu_page(
			'Оптимизатор',
			'Оптимизатор',
			'manage_options',
			Settings::PAGE,
			array( $this, 'page' ),
			'dashicons-performance',
			58
		);
	}

	/**
	 * Регистрация опции.
	 */
	public function register(): void {
		Settings::register();
	}

	/**
	 * Страница настроек.
	 */
	public function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$counts = Database::counts();
		?>
		<div class="wrap oro-app">
			<header class="oro-hero">
				<div class="oro-hero__main">
					<span class="oro-hero__logo dashicons dashicons-performance" aria-hidden="true"></span>
					<div>
						<h1>Aliprofi Optimizer</h1>
						<p>Оптимизация фронтенда и кэша OpenResty</p>
					</div>
				</div>
				<div class="oro-hero__side">
					<span class="oro-chip">v<?php echo esc_html( ALIPROFI_OPTIMIZER_VERSION ); ?></span>
					<a class="oro-hero__link" href="https://aliprofi.ru" target="_blank" rel="noopener">aliprofi.ru</a>
				</div>
			</header>

			<div class="oro-notices">
				<?php
				$this->render_notice();
				settings_errors();
				?>
			</div>

			<nav class="oro-tabs" aria-label="Разделы настроек">
				<?php
				self::tab_button( 'cache', 'Кэш', 'dashicons-performance' );
				self::tab_button( 'js', 'JavaScript', 'dashicons-editor-code' );
				self::tab_button( 'media', 'Медиа', 'dashicons-format-image' );
				self::tab_button( 'database', 'База данных', 'dashicons-database' );
				self::tab_button( 'fonts', 'Шрифты', 'dashicons-editor-textcolor' );
				?>
			</nav>

			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="oro-form" data-form="main">
				<?php
				settings_fields( Settings::GROUP );
				self::scope_hidden( self::main_scope() );
				?>

				<section class="oro-panel is-active" data-panel="cache">
					<?php self::card_open( 'Общее', 'Плагин работает только для гостей. Админка, AJAX, REST, cron и предпросмотр не затрагиваются.' ); ?>
						<?php self::toggle( 'enabled', 'Оптимизация включена', 'Главный выключатель всех фронтенд-модулей.' ); ?>
					<?php self::card_close(); ?>

					<?php self::card_open( 'Страничный кэш OpenResty', 'Сброс fastcgi_cache при изменении контента. Ключ и раскладка каталогов должны совпадать с конфигом Nginx.' ); ?>
						<?php
						self::toggle( 'purge', 'Автоматический сброс кэша', 'Запись, главная, архив типа записи, рубрики, метки и автор. Также комментарии и меню.' );
						self::toggle( 'purge_on_menu', 'Полный сброс при сохранении меню', 'Меню выводится на большинстве страниц, поэтому очищается весь кэш.' );
						self::select_field(
							'purge_method',
							'Способ очистки',
							array(
								'fs'   => 'Файловая система',
								'http' => 'HTTP PURGE',
								'both' => 'Файлы и HTTP PURGE',
							),
							'Файловый способ не требует сетевых запросов, HTTP — работает, если PHP не видит каталог кэша.'
						);
						self::text_field( 'cache_path', 'Каталог кэша', 'Значение из <code>fastcgi_cache_path</code>, например <code>/var/cache/nginx/fastcgi</code>.', '/var/cache/nginx/fastcgi' );
						self::cache_status( (string) Settings::get( 'cache_path' ) );
						self::text_field( 'cache_levels', 'Уровни каталогов', 'Как в <code>levels=1:2</code>. Пусто — файлы лежат прямо в каталоге.', '1:2' );
						self::text_field( 'cache_key', 'Шаблон ключа кэша', 'Должен совпадать с <code>fastcgi_cache_key</code>. Плейсхолдеры: <code>{scheme}{method}{host}{uri}</code>; метод всегда GET.', '{scheme}{method}{host}{uri}' );
						self::text_field( 'purge_endpoint', 'Адрес PURGE', 'Например, <code>https://example.com/purge</code>. Плагин отправит PURGE на этот адрес плюс URI страницы.', 'https://example.com/purge' );
						?>
						<div class="oro-actions">
							<button type="submit" class="button button-primary oro-btn">Сохранить изменения</button>
							<a class="button oro-btn oro-btn-danger" href="<?php echo esc_url( $this->action_url( 'aliprofi_optimizer_purge_all' ) ); ?>" onclick="return confirm('Очистить весь кэш OpenResty?');">Очистить весь кэш</a>
						</div>
					<?php self::card_close(); ?>
				</section>

				<section class="oro-panel" data-panel="js">
					<?php self::card_open( 'Отложенный JavaScript', 'Скрипты не выполняются до mousemove, scroll, touchstart, keydown, click или срабатывания таймера.' ); ?>
						<?php
						self::toggle( 'delay', 'Задерживать JavaScript', 'Полезно для метрик, чатов, рекламы и виджетов: они не мешают первой отрисовке.' );
						self::number_field( 'delay_timeout', 'Таймер запуска, мс', 'Скрипты загрузятся принудительно по таймеру. 0 — только после действия пользователя.', 0, 60000, 500 );
						self::textarea_field( 'delay_exclusions', 'Исключения из задержки', 'По одному фрагменту на строку: часть URL, имя файла или код. Совпадение без учёта регистра. По умолчанию jQuery и jquery-migrate.' );
						self::toggle( 'delay_safe_mode', 'Безопасный режим', 'Не задерживать скрипты из <code>wp-content</code> и <code>wp-includes</code>, jQuery и <code>js-before</code>/<code>js-after</code>. Включайте, если ломается вёрстка или интерактив.' );
						?>
						<div class="oro-note">Задерживаются <strong>все</strong> скрипты, кроме исключений. Если элемент интерфейса появляется с опозданием, добавьте его скрипт в исключения.</div>
						<div class="oro-actions">
							<button type="submit" class="button button-primary oro-btn">Сохранить изменения</button>
						</div>
					<?php self::card_close(); ?>

					<?php self::card_open( 'Defer и очистка head', 'Атрибут defer не меняет порядок выполнения и не трогает async/модули.' ); ?>
						<?php
						self::toggle( 'defer', 'Добавлять defer к скриптам', 'Кроме скриптов с async/defer, модулей и исключений ниже.' );
						self::textarea_field( 'defer_exclude', 'Исключения defer', 'Фрагменты handle или имени файла, по одному на строку.' );
						self::toggle( 'clean_emoji', 'Убрать wp-emoji', 'Скрипт и стили эмодзи WordPress.' );
						self::toggle( 'clean_embed', 'Убрать wp-embed', 'Скрипт wp-embed.min.js и discovery-ссылки oEmbed.' );
						self::toggle( 'clean_meta', 'Убрать лишние мета-теги', 'rsd_link, wlwmanifest_link, wp_generator, wp_shortlink.' );
						?>
						<div class="oro-actions">
							<button type="submit" class="button button-primary oro-btn">Сохранить изменения</button>
						</div>
					<?php self::card_close(); ?>
				</section>

				<section class="oro-panel" data-panel="media">
					<?php self::card_open( 'Изображения', 'Первое крупное изображение записи получает fetchpriority="high" автоматически.' ); ?>
						<?php
						self::textarea_field( 'lazy_exclude', 'Исключения из LazyLoad', 'CSS-классы или имена файлов, по одному на строку. Например, логотип и hero-баннер.' );
						self::toggle( 'img_dims', 'Добавлять width/height в контенте', 'Только тегам без размеров; данные берутся из метаданных вложения. Может добавить запрос к postmeta для картинок без атрибутов.' );
						?>
						<div class="oro-actions">
							<button type="submit" class="button button-primary oro-btn">Сохранить изменения</button>
						</div>
					<?php self::card_close(); ?>

					<?php self::card_open( 'YouTube', 'Лёгкий фасад вместо iframe: превью с i.ytimg.com, плеер загружается только по клику.' ); ?>
						<?php self::toggle( 'youtube', 'Включить фасад YouTube', 'Работает для oEmbed и прямых iframe youtube.com / youtu.be.' ); ?>
						<div class="oro-actions">
							<button type="submit" class="button button-primary oro-btn">Сохранить изменения</button>
						</div>
					<?php self::card_close(); ?>
				</section>

				<section class="oro-panel" data-panel="fonts">
					<?php self::card_open( 'Google Fonts', 'CSS и .woff2 скачиваются в <code>wp-content/cache/aliprofi-optimizer-fonts/</code> при первом заходе гостя и обновляются раз в 7 дней.' ); ?>
						<?php
						self::toggle( 'fonts', 'Локальные Google Fonts', 'Ссылки на fonts.googleapis.com заменяются на локальные, без внешнего домена в критическом пути.' );
						self::textarea_field( 'fonts_preload', 'Preload шрифтов', 'URL или пути .woff2, по одному на строку. Выводятся как <code>&lt;link rel="preload" as="font" crossorigin&gt;</code>.' );
						?>
						<div class="oro-actions">
							<button type="submit" class="button button-primary oro-btn">Сохранить изменения</button>
							<a class="button oro-btn" href="<?php echo esc_url( $this->action_url( 'aliprofi_optimizer_clear_fonts' ) ); ?>">Очистить кэш шрифтов</a>
						</div>
					<?php self::card_close(); ?>
				</section>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="oro-form" data-form="db">
				<input type="hidden" name="action" value="aliprofi_optimizer_db_cleanup">
				<?php
				wp_nonce_field( 'aliprofi_optimizer_db_cleanup' );
				self::scope_hidden( self::db_keys() );
				?>

				<section class="oro-panel" data-panel="database">
					<?php self::card_open( 'Очистка записей', 'Ревизии, авто-черновики и записи в корзине удаляются безвозвратно.' ); ?>
						<?php
						self::toggle( 'db_revisions', 'Ревизии', Database::count_text( 'db_revisions', (int) $counts['db_revisions'] ) . ' в базе данных.' );
						self::toggle( 'db_auto_drafts', 'Авто-черновики', Database::count_text( 'db_auto_drafts', (int) $counts['db_auto_drafts'] ) . ' в базе данных.' );
						self::toggle( 'db_trashed_posts', 'Записи в корзине', Database::count_text( 'db_trashed_posts', (int) $counts['db_trashed_posts'] ) . ' в базе данных.' );
						?>
					<?php self::card_close(); ?>

					<?php self::card_open( 'Очистка комментариев', 'Спам и комментарии в корзине удаляются сразу, минуя корзину.' ); ?>
						<?php
						self::toggle( 'db_spam_comments', 'Спам-комментарии', Database::count_text( 'db_spam_comments', (int) $counts['db_spam_comments'] ) . ' в базе данных.' );
						self::toggle( 'db_trashed_comments', 'Удалённые комментарии', Database::count_text( 'db_trashed_comments', (int) $counts['db_trashed_comments'] ) . ' в базе данных.' );
						?>
					<?php self::card_close(); ?>

					<?php self::card_open( 'Транзиенты и таблицы', 'Транзиенты — временные настройки, они безопасно создаются заново. OPTIMIZE TABLE применяется к таблицам не-InnoDB с свободным местом.' ); ?>
						<?php
						self::toggle( 'db_transients', 'Все транзиенты', Database::count_text( 'db_transients', (int) $counts['db_transients'] ) . ' в базе данных.' );
						self::toggle( 'db_optimize_tables', 'Оптимизация таблиц', Database::count_text( 'db_optimize_tables', (int) $counts['db_optimize_tables'] ) . ' в базе данных.' );
						?>
					<?php self::card_close(); ?>

					<?php self::card_open( 'Автоматическая очистка', 'Задание WP-Cron запускается при посещениях сайта и очищает выбранные выше пункты.' ); ?>
						<?php
						self::toggle( 'db_schedule', 'Очищать по расписанию', 'Отключено — очистка выполняется только по кнопке.' );
						self::select_field(
							'db_frequency',
							'Частота',
							array(
								'daily'   => 'Каждый день',
								'weekly'  => 'Раз в неделю',
								'monthly' => 'Раз в месяц',
							)
						);
						?>
					<?php self::card_close(); ?>

					<div class="oro-card oro-card--warning">
						<div class="oro-card__body">
							<strong>Удаление необратимо.</strong> Перед очисткой сделайте резервную копию базы данных. За один запуск обрабатывается до 500 записей или комментариев и до 500 транзиентов — при большом объёме повторите очистку.
						</div>
					</div>

					<div class="oro-actions oro-actions--db">
						<button type="submit" class="button button-primary oro-btn" name="oro_db_submit" value="save">Сохранить настройки</button>
						<button type="submit" class="button oro-btn oro-btn-danger" name="oro_db_submit" value="cleanup" onclick="return confirm('Удалить выбранные элементы без возможности восстановления?');">Очистить выбранное</button>
					</div>
				</section>
			</form>

			<footer class="oro-credit">
				Aliprofi Optimizer <?php echo esc_html( ALIPROFI_OPTIMIZER_VERSION ); ?> ·
				<a href="https://aliprofi.ru" target="_blank" rel="noopener">aliprofi.ru</a>
			</footer>
		</div>

		<style>
			.oro-app {
				--oro-ink: #0f172a;
				--oro-muted: #64748b;
				--oro-line: #e2e8f0;
				--oro-accent: #f97316;
				--oro-accent-soft: #fff7ed;
				--oro-ok: #15803d;
				--oro-warn: #b45309;
				--oro-bad: #b91c1c;
				max-width: 1120px;
				margin: 24px 20px 40px 0;
				color: var(--oro-ink);
				font-size: 14px;
			}
			.oro-hero {
				display: flex;
				justify-content: space-between;
				align-items: center;
				gap: 16px;
				flex-wrap: wrap;
				padding: 22px 26px;
				border-radius: 16px;
				background:
					radial-gradient(1200px 200px at 10% -40%, rgba(249, 115, 22, .45), transparent 70%),
					linear-gradient(135deg, #0f172a, #1e293b);
				color: #fff;
				box-shadow: 0 14px 30px -18px rgba(15, 23, 42, .8);
			}
			.oro-hero__main { display: flex; align-items: center; gap: 14px; }
			.oro-hero__logo {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 46px;
				height: 46px;
				border-radius: 12px;
				background: linear-gradient(135deg, #f97316, #fbbf24);
				color: #1e293b;
				font-size: 26px;
				box-shadow: inset 0 1px 0 rgba(255, 255, 255, .35);
			}
			.oro-hero h1 { margin: 0; color: #fff; font-size: 22px; line-height: 1.2; }
			.oro-hero p { margin: 4px 0 0; color: #cbd5e1; font-size: 13px; }
			.oro-hero__side { display: flex; align-items: center; gap: 12px; margin-left: auto; }
			.oro-chip {
				display: inline-flex;
				padding: 3px 10px;
				border-radius: 999px;
				background: rgba(255, 255, 255, .12);
				border: 1px solid rgba(255, 255, 255, .18);
				font-size: 12px;
				font-weight: 600;
			}
			.oro-hero__link { color: #fdba74; text-decoration: none; font-weight: 600; }
			.oro-hero__link:hover { color: #fff; }
			.oro-notices { margin: 14px 0 0; }
			.oro-notices .notice { margin: 0 0 12px; border-radius: 12px; }
			.oro-alert {
				display: flex;
				align-items: flex-start;
				gap: 10px;
				margin: 0 0 14px;
				padding: 12px 14px;
				border: 1px solid;
				border-radius: 12px;
				font-size: 13px;
				line-height: 1.5;
				animation: oro-fade .18s ease;
			}
			.oro-alert p { flex: 1; margin: 0; }
			.oro-alert .dashicons { flex: 0 0 18px; width: 18px; height: 18px; margin-top: 1px; font-size: 18px; }
			.oro-alert__close {
				margin: -2px -4px 0 auto;
				padding: 0 4px;
				border: 0;
				background: transparent;
				color: inherit;
				font-size: 20px;
				line-height: 1;
				opacity: .55;
				cursor: pointer;
			}
			.oro-alert__close:hover { opacity: 1; }
			.oro-alert--success { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
			.oro-alert--error { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
			.oro-alert--warning { background: #fffbeb; border-color: #fde68a; color: #92400e; }
			.oro-tabs {
				display: flex;
				gap: 4px;
				flex-wrap: wrap;
				margin: 18px 0 16px;
				padding: 6px;
				background: #fff;
				border: 1px solid var(--oro-line);
				border-radius: 12px;
			}
			.oro-tab {
				display: inline-flex;
				align-items: center;
				gap: 6px;
				padding: 9px 14px;
				border: 0;
				border-radius: 8px;
				background: transparent;
				color: var(--oro-muted);
				font-size: 13px;
				font-weight: 600;
				cursor: pointer;
				line-height: 1;
			}
			.oro-tab .dashicons { font-size: 17px; width: 17px; height: 17px; }
			.oro-tab:hover { background: #f8fafc; color: var(--oro-ink); }
			.oro-tab.is-active { background: var(--oro-ink); color: #fff; }
			.oro-panel { display: none; }
			.oro-panel.is-active { display: block; animation: oro-fade .18s ease; }
			@keyframes oro-fade { from { opacity: 0; transform: translateY(3px); } to { opacity: 1; transform: none; } }
			.oro-card {
				background: #fff;
				border: 1px solid var(--oro-line);
				border-radius: 14px;
				margin-bottom: 16px;
				box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
			}
			.oro-card__head { padding: 18px 22px 0; }
			.oro-card__head h2 { margin: 0; font-size: 16px; }
			.oro-card__head p { margin: 6px 0 0; color: var(--oro-muted); font-size: 12.5px; line-height: 1.5; }
			.oro-card__body { padding: 6px 22px 16px; }
			.oro-card__head + .oro-card__body { padding-top: 4px; }
			.oro-card--warning { border-color: #fed7aa; background: #fffaf5; }
			.oro-card--warning .oro-card__body { color: #7c2d12; line-height: 1.6; }
			.oro-field {
				display: flex;
				align-items: flex-start;
				gap: 14px;
				padding: 14px 0;
				border-top: 1px solid #f1f5f9;
			}
			.oro-card__body > .oro-field:first-child { border-top: 0; }
			.oro-field--toggle {
				margin: 0 -10px;
				padding: 13px 10px;
				border-radius: 10px;
				transition: background .15s ease;
			}
			.oro-field--toggle:hover { background: #f8fafc; }
			.oro-field--stack { display: block; }
			.oro-field__text { flex: 1; padding-top: 1px; }
			.oro-field__title { display: block; font-weight: 600; cursor: pointer; }
			.oro-field__desc { margin: 3px 0 0; color: var(--oro-muted); font-size: 12.5px; line-height: 1.55; }
			.oro-field__desc code { font-size: 12px; }
			.oro-switch {
				position: relative;
				flex: 0 0 44px;
				width: 44px;
				height: 24px;
				margin-top: 1px;
				cursor: pointer;
			}
			.oro-switch input {
				position: absolute;
				inset: 0;
				width: 100%;
				height: 100%;
				margin: 0;
				opacity: 0;
				cursor: pointer;
				z-index: 2;
			}
			.oro-switch__track {
				position: relative;
				display: block;
				width: 44px;
				height: 24px;
				border-radius: 999px;
				background: #cbd5e1;
				transition: background .18s ease;
			}
			.oro-switch__track::after {
				content: "";
				position: absolute;
				top: 3px;
				left: 3px;
				width: 18px;
				height: 18px;
				border-radius: 50%;
				background: #fff;
				box-shadow: 0 1px 2px rgba(15, 23, 42, .25);
				transition: transform .18s ease;
			}
			.oro-switch input:checked + .oro-switch__track { background: var(--oro-accent); }
			.oro-switch input:checked + .oro-switch__track::after { transform: translateX(20px); }
			.oro-switch input:focus-visible + .oro-switch__track { outline: 2px solid #93c5fd; outline-offset: 2px; }
			.oro-input, .oro-textarea, .oro-select {
				width: 100%;
				max-width: 580px;
				margin-top: 8px;
				padding: 8px 11px;
				border: 1px solid #cbd5e1;
				border-radius: 9px;
				background: #fff;
				color: var(--oro-ink);
				font-size: 13px;
				box-shadow: inset 0 1px 2px rgba(15, 23, 42, .04);
			}
			.oro-input:focus, .oro-textarea:focus, .oro-select:focus {
				border-color: var(--oro-accent);
				box-shadow: 0 0 0 3px rgba(249, 115, 22, .18);
				outline: 0;
			}
			.oro-textarea { min-height: 112px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; line-height: 1.55; }
			.oro-select { max-width: 320px; }
			.oro-note {
				margin: 6px 0 14px;
				padding: 10px 14px;
				border-radius: 10px;
				background: var(--oro-accent-soft);
				border: 1px solid #fed7aa;
				color: #7c2d12;
				font-size: 12.5px;
				line-height: 1.55;
			}
			.oro-status {
				display: inline-flex;
				align-items: center;
				gap: 6px;
				padding: 2px 9px;
				border-radius: 999px;
				font-size: 11.5px;
				font-weight: 600;
			}
			.oro-status::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
			.oro-status--ok { background: #dcfce7; color: var(--oro-ok); }
			.oro-status--warn { background: #fef3c7; color: var(--oro-warn); }
			.oro-status--bad { background: #fee2e2; color: var(--oro-bad); }
			.oro-form .oro-actions {
				display: flex;
				align-items: center;
				gap: 10px;
				flex-wrap: wrap;
				margin-top: 8px;
				padding-top: 16px;
				border-top: 1px dashed var(--oro-line);
			}
			.oro-form .oro-actions--db { border-top: 0; padding-top: 4px; }
			.oro-app .button.oro-btn {
				display: inline-flex;
				align-items: center;
				height: auto;
				min-height: 34px;
				padding: 6px 16px;
				border-radius: 9px;
				font-weight: 600;
				line-height: 1.4;
			}
			.oro-app .button-primary.oro-btn {
				background: var(--oro-accent);
				border-color: var(--oro-accent);
				color: #fff;
			}
			.oro-app .button-primary.oro-btn:hover,
			.oro-app .button-primary.oro-btn:focus {
				background: #ea580c;
				border-color: #ea580c;
				color: #fff;
			}
			.oro-app .button.oro-btn-danger { color: var(--oro-bad); border-color: #fecaca; }
			.oro-app .button.oro-btn-danger:hover { background: #fef2f2; border-color: #fca5a5; color: var(--oro-bad); }
			.oro-credit { margin-top: 22px; color: var(--oro-muted); font-size: 12px; }
			.oro-credit a { color: var(--oro-muted); }
			@media (max-width: 782px) {
				.oro-app { margin-right: 10px; }
				.oro-hero { padding: 18px; }
				.oro-tab { padding: 8px 10px; }
				.oro-tab .dashicons { display: none; }
				.oro-card__head, .oro-card__body { padding-left: 16px; padding-right: 16px; }
				.oro-field { gap: 10px; }
			}
		</style>

		<script>
			(function () {
				Array.prototype.slice.call(document.querySelectorAll('.oro-alert__close')).forEach(function (btn) {
					btn.addEventListener('click', function () {
						var box = btn.closest('.oro-alert');
						if (box) { box.remove(); }
					});
				});

				var tabs = Array.prototype.slice.call(document.querySelectorAll('.oro-tab'));
				var panels = Array.prototype.slice.call(document.querySelectorAll('.oro-panel'));
				var forms = Array.prototype.slice.call(document.querySelectorAll('.oro-form'));
				if (!tabs.length) { return; }

				function activate(id) {
					tabs.forEach(function (tab) {
						var on = tab.getAttribute('data-tab') === id;
						tab.classList.toggle('is-active', on);
						tab.setAttribute('aria-selected', on ? 'true' : 'false');
					});
					panels.forEach(function (panel) {
						panel.classList.toggle('is-active', panel.getAttribute('data-panel') === id);
					});
					forms.forEach(function (form) {
						form.style.display = form.querySelector('.oro-panel.is-active') ? '' : 'none';
					});
					try { localStorage.setItem('oroTab', id); } catch (e) {}
				}

				tabs.forEach(function (tab) {
					tab.addEventListener('click', function () { activate(tab.getAttribute('data-tab')); });
				});

				var saved = null;
				try { saved = localStorage.getItem('oroTab'); } catch (e) {}
				var ids = tabs.map(function (tab) { return tab.getAttribute('data-tab'); });
				activate(saved && ids.indexOf(saved) !== -1 ? saved : ids[0]);
			}());
		</script>
		<?php
	}

	/**
	 * Кнопка вкладки.
	 *
	 * @param string $id    Идентификатор панели.
	 * @param string $label Название.
	 * @param string $icon  Класс Dashicon.
	 */
	private static function tab_button( string $id, string $label, string $icon ): void {
		printf(
			'<button type="button" class="oro-tab" role="tab" aria-selected="false" data-tab="%s"><span class="dashicons %s" aria-hidden="true"></span>%s</button>',
			esc_attr( $id ),
			esc_attr( $icon ),
			esc_html( $label )
		);
	}

	/**
	 * Открывает карточку.
	 *
	 * @param string $title Заголовок.
	 * @param string $desc  Описание (разрешён HTML).
	 */
	private static function card_open( string $title, string $desc = '' ): void {
		echo '<section class="oro-card"><div class="oro-card__head">';
		printf( '<h2>%s</h2>', esc_html( $title ) );
		if ( '' !== $desc ) {
			printf( '<p>%s</p>', wp_kses_post( $desc ) );
		}
		echo '</div><div class="oro-card__body">';
	}

	/**
	 * Закрывает карточку.
	 */
	private static function card_close(): void {
		echo '</div></section>';
	}

	/**
	 * Тумблер.
	 *
	 * @param string $key   Ключ настройки.
	 * @param string $title Заголовок.
	 * @param string $desc  Описание (разрешён HTML).
	 */
	private static function toggle( string $key, string $title, string $desc = '' ): void {
		$id = Settings::OPTION . '-' . $key;
		printf(
			'<div class="oro-field oro-field--toggle"><label class="oro-switch" for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s><span class="oro-switch__track"></span></label><div class="oro-field__text"><label class="oro-field__title" for="%1$s">%4$s</label>',
			esc_attr( $id ),
			esc_attr( Settings::OPTION . '[' . $key . ']' ),
			checked( 1, (int) Settings::get( $key ), false ),
			esc_html( $title )
		);
		if ( '' !== $desc ) {
			printf( '<p class="oro-field__desc">%s</p>', wp_kses_post( $desc ) );
		}
		echo '</div></div>';
	}

	/**
	 * Текстовое поле.
	 *
	 * @param string $key         Ключ.
	 * @param string $title       Заголовок.
	 * @param string $desc        Описание.
	 * @param string $placeholder Плейсхолдер.
	 */
	private static function text_field( string $key, string $title, string $desc = '', string $placeholder = '' ): void {
		$id = Settings::OPTION . '-' . $key;
		printf(
			'<div class="oro-field oro-field--stack"><label class="oro-field__title" for="%1$s">%2$s</label>',
			esc_attr( $id ),
			esc_html( $title )
		);
		if ( '' !== $desc ) {
			printf( '<p class="oro-field__desc">%s</p>', wp_kses_post( $desc ) );
		}
		printf(
			'<input type="text" class="oro-input code" id="%1$s" name="%2$s" value="%3$s" placeholder="%4$s"></div>',
			esc_attr( $id ),
			esc_attr( Settings::OPTION . '[' . $key . ']' ),
			esc_attr( (string) Settings::get( $key ) ),
			esc_attr( $placeholder )
		);
	}

	/**
	 * Числовое поле.
	 *
	 * @param string $key   Ключ.
	 * @param string $title Заголовок.
	 * @param string $desc  Описание.
	 * @param int    $min   Минимум.
	 * @param int    $max   Максимум.
	 * @param int    $step  Шаг.
	 */
	private static function number_field( string $key, string $title, string $desc, int $min, int $max, int $step ): void {
		$id = Settings::OPTION . '-' . $key;
		printf(
			'<div class="oro-field oro-field--stack"><label class="oro-field__title" for="%1$s">%2$s</label>',
			esc_attr( $id ),
			esc_html( $title )
		);
		if ( '' !== $desc ) {
			printf( '<p class="oro-field__desc">%s</p>', wp_kses_post( $desc ) );
		}
		printf(
			'<input type="number" class="oro-input small-text" id="%1$s" name="%2$s" value="%3$s" min="%4$d" max="%5$d" step="%6$d"></div>',
			esc_attr( $id ),
			esc_attr( Settings::OPTION . '[' . $key . ']' ),
			esc_attr( (string) (int) Settings::get( $key ) ),
			(int) $min,
			(int) $max,
			(int) $step
		);
	}

	/**
	 * Многострочное поле.
	 *
	 * @param string $key   Ключ.
	 * @param string $title Заголовок.
	 * @param string $desc  Описание.
	 */
	private static function textarea_field( string $key, string $title, string $desc = '' ): void {
		$id = Settings::OPTION . '-' . $key;
		printf(
			'<div class="oro-field oro-field--stack"><label class="oro-field__title" for="%1$s">%2$s</label>',
			esc_attr( $id ),
			esc_html( $title )
		);
		if ( '' !== $desc ) {
			printf( '<p class="oro-field__desc">%s</p>', wp_kses_post( $desc ) );
		}
		printf(
			'<textarea class="oro-textarea code" id="%1$s" name="%2$s" rows="6">%3$s</textarea></div>',
			esc_attr( $id ),
			esc_attr( Settings::OPTION . '[' . $key . ']' ),
			esc_textarea( (string) Settings::get( $key ) )
		);
	}

	/**
	 * Выпадающий список.
	 *
	 * @param string               $key     Ключ.
	 * @param string               $title   Заголовок.
	 * @param array<string,string> $options Варианты.
	 * @param string               $desc    Описание.
	 */
	private static function select_field( string $key, string $title, array $options, string $desc = '' ): void {
		$id      = Settings::OPTION . '-' . $key;
		$current = (string) Settings::get( $key );

		printf(
			'<div class="oro-field oro-field--stack"><label class="oro-field__title" for="%1$s">%2$s</label>',
			esc_attr( $id ),
			esc_html( $title )
		);
		if ( '' !== $desc ) {
			printf( '<p class="oro-field__desc">%s</p>', wp_kses_post( $desc ) );
		}
		printf( '<select class="oro-select" id="%s" name="%s">', esc_attr( $id ), esc_attr( Settings::OPTION . '[' . $key . ']' ) );
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select></div>';
	}

	/**
	 * Статус каталога кэша.
	 *
	 * @param string $path Путь.
	 */
	private static function cache_status( string $path ): void {
		if ( '' === $path ) {
			$class = 'warn';
			$text  = 'Путь не задан — файловая очистка отключена';
		} elseif ( ! Settings::is_safe_dir( $path ) ) {
			$class = 'bad';
			$text  = 'Путь отклонён проверкой безопасности';
		} elseif ( ! is_dir( $path ) ) {
			$class = 'warn';
			$text  = 'Каталог не найден или недоступен PHP-FPM';
		} elseif ( ! is_writable( $path ) ) {
			$class = 'warn';
			$text  = 'Каталог найден, но недоступен для записи';
		} else {
			$class = 'ok';
			$text  = 'Каталог доступен для очистки';
		}

		printf( '<div class="oro-field"><span class="oro-status oro-status--%s">%s</span></div>', esc_attr( $class ), esc_html( $text ) );
	}

	/**
	 * Скрытое поле со списком ключей, которые сохраняет текущая форма.
	 *
	 * @param string[] $keys Ключи.
	 */
	private static function scope_hidden( array $keys ): void {
		printf(
			'<input type="hidden" name="%s" value="%s">',
			esc_attr( Settings::OPTION . '[_scope]' ),
			esc_attr( implode( ',', $keys ) )
		);
	}

	/**
	 * Ключи, которыми управляет вкладка «База данных».
	 *
	 * @return string[]
	 */
	private static function db_keys(): array {
		return array_merge( array_keys( Database::types() ), array( 'db_schedule', 'db_frequency' ) );
	}

	/**
	 * Ключи основной формы (всё, кроме БД).
	 *
	 * @return string[]
	 */
	private static function main_scope(): array {
		return array_values( array_diff( array_keys( Settings::defaults() ), self::db_keys() ) );
	}

	/**
	 * Меню «Очистить» в админ-баре.
	 *
	 * @param \WP_Admin_Bar $bar Админ-бар.
	 */
	public function bar( $bar ): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'aliprofi-optimizer-purge',
				'title' => 'Очистить',
				'href'  => '#',
			)
		);

		$bar->add_node(
			array(
				'id'     => 'aliprofi-optimizer-purge-all',
				'parent' => 'aliprofi-optimizer-purge',
				'title'  => 'Весь сайт',
				'href'   => wp_nonce_url(
					add_query_arg( 'action', 'aliprofi_optimizer_purge_all', admin_url( 'admin-post.php' ) ),
					'aliprofi_optimizer_purge_all'
				),
				'meta'   => array( 'onclick' => 'return confirm("Очистить весь кэш сайта?");' ),
			)
		);

		$current = $this->current_url();
		if ( '' !== $current ) {
			$bar->add_node(
				array(
					'id'     => 'aliprofi-optimizer-purge-page',
					'parent' => 'aliprofi-optimizer-purge',
					'title'  => 'Эту страницу',
					'href'   => wp_nonce_url(
						add_query_arg(
							array(
								'action' => 'aliprofi_optimizer_purge_page',
								'url'    => $current,
							),
							admin_url( 'admin-post.php' )
						),
						'aliprofi_optimizer_purge_page'
					),
					'meta'   => array( 'onclick' => 'return confirm("Очистить кэш этой страницы?");' ),
				)
			);
		}
	}

	/**
	 * URL текущей страницы для точечной очистки (только фронтенд).
	 */
	private function current_url(): string {
		if ( is_admin() ) {
			return '';
		}

		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $host || '' === $uri ) {
			return '';
		}
		if ( '/' !== $uri[0] ) {
			$uri = '/' . $uri;
		}

		return esc_url_raw( ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri );
	}

	/**
	 * Обработчик очистки кэша одной страницы.
	 */
	public function handle_purge_page(): void {
		$this->guard( 'aliprofi_optimizer_purge_page' );

		$raw = isset( $_GET['url'] ) && is_string( $_GET['url'] ) ? wp_unslash( $_GET['url'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce проверен в guard().
		$url = esc_url_raw( $raw );

		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			$this->redirect_back( 'page_purge_failed' );
			return;
		}

		$this->redirect_to_url( $url, Cache::purge_url( $url ) ? 'page_purged' : 'page_purge_failed' );
	}

	/**
	 * Обработчик полной очистки.
	 */
	public function handle_purge_all(): void {
		$this->guard( 'aliprofi_optimizer_purge_all' );
		$this->redirect_back( Cache::purge_all() ? 'purged' : 'purge_failed' );
	}

	/**
	 * Обработчик очистки кэша шрифтов.
	 */
	public function handle_clear_fonts(): void {
		$this->guard( 'aliprofi_optimizer_clear_fonts' );
		$this->redirect_back( Fonts::purge_files() ? 'fonts_cleared' : 'fonts_failed' );
	}

	/**
	 * Обработчик вкладки «База данных»: сохраняет настройки и при необходимости запускает очистку.
	 */
	public function handle_db_cleanup(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Доступ запрещён.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'aliprofi_optimizer_db_cleanup' );

		$raw = isset( $_POST[ Settings::OPTION ] ) && is_array( $_POST[ Settings::OPTION ] )
			? wp_unslash( $_POST[ Settings::OPTION ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- массив проходит Settings::sanitize().
			: array();

		update_option( Settings::OPTION, Settings::sanitize( $raw ) );

		$mode = isset( $_POST['oro_db_submit'] ) ? sanitize_key( wp_unslash( $_POST['oro_db_submit'] ) ) : 'save';
		if ( 'cleanup' !== $mode ) {
			$this->redirect_back( 'db_saved' );
			return;
		}

		$items = array();
		foreach ( array_keys( Database::types() ) as $key ) {
			if ( ! empty( $raw[ $key ] ) ) {
				$items[] = $key;
			}
		}

		if ( ! $items ) {
			$this->redirect_back( 'db_none' );
			return;
		}

		set_transient( $this->db_result_key(), Database::cleanup( $items ), 5 * MINUTE_IN_SECONDS );
		$this->redirect_back( 'db_done' );
	}

	/**
	 * Уведомление в админке.
	 */
	public function notice(): void {
		if ( $this->is_own_screen() ) {
			return;
		}
		$notice = $this->current_notice();
		if ( ! $notice ) {
			return;
		}
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $notice[0] ),
			esc_html( $notice[1] )
		);
	}

	/**
	 * Наша ли это страница настроек (на ней уведомление выводится внутри шапки контента).
	 */
	private function is_own_screen(): bool {
		if ( isset( $_GET['page'] ) && Settings::PAGE === sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- только сравнение слага страницы.
			return true;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && 'toplevel_page_' . Settings::PAGE === $screen->id;
	}

	/**
	 * Уведомление внутри страницы настроек.
	 */
	private function render_notice(): void {
		$notice = $this->current_notice();
		if ( ! $notice ) {
			return;
		}

		$tones = array(
			'success' => array( 'dashicons-yes-alt', 'success' ),
			'error'   => array( 'dashicons-warning', 'error' ),
			'warning' => array( 'dashicons-info', 'warning' ),
		);
		$tone = $tones[ $notice[0] ] ?? $tones['success'];
		$role = 'error' === $tone[1] ? 'alert' : 'status';

		printf(
			'<div class="oro-alert oro-alert--%1$s" role="%4$s"><span class="dashicons %2$s" aria-hidden="true"></span><p>%3$s</p><button type="button" class="oro-alert__close" aria-label="Закрыть уведомление">&times;</button></div>',
			esc_attr( $tone[1] ),
			esc_attr( $tone[0] ),
			esc_html( $notice[1] ),
			esc_attr( $role )
		);
	}

	/**
	 * Всплывающее уведомление на фронтенде после очистки из админ-бара.
	 */
	public function toast(): void {
		$notice = $this->current_notice();
		if ( ! $notice ) {
			return;
		}
		$bg = 'success' === $notice[0] ? '#00a32a' : '#d63638';
		printf(
			'<div style="position:fixed;top:46px;right:20px;z-index:100000;background:%s;color:#fff;padding:10px 16px;border-radius:4px;font:14px/1.4 sans-serif;box-shadow:0 2px 10px rgba(0,0,0,.25)">%s</div>',
			esc_attr( $bg ),
			esc_html( $notice[1] )
		);
	}

	/**
	 * Ссылка «Настройки» в списке плагинов.
	 *
	 * @param string[] $links Ссылки.
	 * @return string[]
	 */
	public function links( array $links ): array {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . Settings::PAGE ) ) . '">Настройки</a>'
		);

		return $links;
	}

	/**
	 * Проверка прав и nonce для admin-post.
	 *
	 * @param string $action Nonce-действие.
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Доступ запрещён.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Редирект назад с параметром уведомления.
	 *
	 * @param string $notice Код уведомления.
	 */
	private function redirect_back( string $notice ): void {
		$target = wp_get_referer();
		if ( ! $target ) {
			$target = admin_url( 'admin.php?page=' . Settings::PAGE );
		}
		$target = remove_query_arg( 'oro_notice', $target );
		$target = add_query_arg( 'oro_notice', $notice, $target );

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Редирект на конкретный URL (после очистки страницы).
	 *
	 * @param string $url    Куда вернуться.
	 * @param string $notice Код уведомления.
	 */
	private function redirect_to_url( string $url, string $notice ): void {
		$target = remove_query_arg( 'oro_notice', $url );
		$target = add_query_arg( 'oro_notice', $notice, $target );

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Текущее уведомление из query-параметра.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function current_notice(): ?array {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['oro_notice'] ) ) {
			return null;
		}

		if ( ! is_string( $_GET['oro_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- только чтение флага.
			return null;
		}

		$key = sanitize_key( wp_unslash( $_GET['oro_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- только чтение флага.

		switch ( $key ) {
			case 'purged':
				return array( 'success', 'Кэш OpenResty очищен.' );
			case 'purge_failed':
				return array( 'error', 'Не удалось очистить кэш. Проверьте путь, права и настройки PURGE.' );
			case 'page_purged':
				return array( 'success', 'Кэш этой страницы очищен.' );
			case 'page_purge_failed':
				return array( 'error', 'Не удалось очистить кэш этой страницы. Проверьте настройки сброса.' );
			case 'fonts_cleared':
				return array( 'success', 'Локальный кэш шрифтов очищен.' );
			case 'fonts_failed':
				return array( 'error', 'Не удалось очистить кэш шрифтов.' );
			case 'db_saved':
				return array( 'success', 'Настройки очистки базы данных сохранены.' );
			case 'db_none':
				return array( 'warning', 'Не выбрано ни одного пункта очистки.' );
			case 'db_done':
				return $this->db_result_notice();
		}

		return null;
	}

	/**
	 * Сообщение с результатом очистки БД.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function db_result_notice(): array {
		$result = get_transient( $this->db_result_key() );
		delete_transient( $this->db_result_key() );

		if ( ! is_array( $result ) ) {
			return array( 'warning', 'Очистка завершена: удалять было нечего.' );
		}

		$parts = array();
		foreach ( $result as $type => $count ) {
			$count = (int) $count;
			if ( $count > 0 && isset( Database::types()[ $type ] ) ) {
				$parts[] = Database::count_text( $type, $count );
			}
		}

		if ( ! $parts ) {
			return array( 'warning', 'Очистка завершена: удалять было нечего.' );
		}

		return array( 'success', 'Очищено: ' . implode( ', ', $parts ) . '.' );
	}

	/**
	 * Ключ транзиента с результатом очистки для текущего пользователя.
	 */
	private function db_result_key(): string {
		return 'aliprofi_optimizer_db_result_' . get_current_user_id();
	}

	/**
	 * URL admin-post с nonce.
	 *
	 * @param string $action Действие.
	 */
	private function action_url( string $action ): string {
		return wp_nonce_url(
			add_query_arg( 'action', $action, admin_url( 'admin-post.php' ) ),
			$action
		);
	}
}
