<?php
/**
 * Очистка базы данных и автоматическое обслуживание по расписанию.
 *
 * @package Aliprofi_Optimizer
 */

namespace AliProfi\Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Удаление мусора из БД: ревизии, авто-черновики, корзина, спам, транзиенты, OPTIMIZE TABLE.
 */
final class Database {

	public const CRON = 'aliprofi_optimizer_db_cleanup';

	private const LIMIT = 500;

	/**
	 * Тип очистки и формы слова для русского счётчика.
	 *
	 * @return array<string, array{label: string, forms: string[]}>
	 */
	public static function types(): array {
		return array(
			'db_revisions'        => array(
				'label' => 'Ревизии',
				'forms' => array( 'ревизия', 'ревизии', 'ревизий' ),
			),
			'db_auto_drafts'      => array(
				'label' => 'Авто-черновики',
				'forms' => array( 'авто-черновик', 'авто-черновика', 'авто-черновиков' ),
			),
			'db_trashed_posts'    => array(
				'label' => 'Записи в корзине',
				'forms' => array( 'запись в корзине', 'записи в корзине', 'записей в корзине' ),
			),
			'db_spam_comments'    => array(
				'label' => 'Спам-комментарии',
				'forms' => array( 'спам-комментарий', 'спам-комментария', 'спам-комментариев' ),
			),
			'db_trashed_comments' => array(
				'label' => 'Удалённые комментарии',
				'forms' => array( 'комментарий в корзине', 'комментария в корзине', 'комментариев в корзине' ),
			),
			'db_transients'       => array(
				'label' => 'Транзиенты',
				'forms' => array( 'транзиент', 'транзиента', 'транзиентов' ),
			),
			'db_optimize_tables'  => array(
				'label' => 'Таблицы',
				'forms' => array( 'таблица к оптимизации', 'таблицы к оптимизации', 'таблиц к оптимизации' ),
			),
		);
	}

	/**
	 * Хуки расписания.
	 */
	public function run(): void {
		add_filter( 'cron_schedules', array( $this, 'schedules' ) );
		add_action( 'init', array( $this, 'schedule' ) );
		add_action( self::CRON, array( $this, 'cron' ) );
	}

	/**
	 * Интервалы для автоочистки.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Расписания.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function schedules( $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : array();

		$schedules['aliprofi_weekly']  = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => 'Раз в неделю (Aliprofi Optimizer)',
		);
		$schedules['aliprofi_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => 'Раз в месяц (Aliprofi Optimizer)',
		);

		return $schedules;
	}

	/**
	 * Создаёт или снимает задание cron при изменении настроек.
	 */
	public function schedule(): void {
		if ( ! Settings::is_on( 'db_schedule' ) ) {
			if ( wp_next_scheduled( self::CRON ) ) {
				wp_clear_scheduled_hook( self::CRON );
			}
			return;
		}

		$recurrence = self::recurrence();
		$event      = wp_get_scheduled_event( self::CRON );

		if ( $event && $event->schedule === $recurrence ) {
			return;
		}
		if ( $event ) {
			wp_clear_scheduled_hook( self::CRON );
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, $recurrence, self::CRON );
	}

	/**
	 * Задание cron: чистит выбранные в настройках пункты.
	 */
	public function cron(): void {
		$items = self::selected();
		if ( $items ) {
			self::cleanup( $items );
		}
	}

	/**
	 * Выбранные пользователем пункты очистки.
	 *
	 * @return string[]
	 */
	public static function selected(): array {
		$items = array();
		foreach ( array_keys( self::types() ) as $key ) {
			if ( Settings::is_on( $key ) ) {
				$items[] = $key;
			}
		}

		return $items;
	}

	/**
	 * Количество элементов по каждому пункту.
	 *
	 * @return array<string, int>
	 */
	public static function counts(): array {
		$counts = array();
		foreach ( array_keys( self::types() ) as $key ) {
			$counts[ $key ] = self::count( $key );
		}

		return $counts;
	}

	/**
	 * Количество элементов одного типа.
	 *
	 * @param string $type Тип.
	 */
	public static function count( string $type ): int {
		global $wpdb;

		switch ( $type ) {
			case 'db_revisions':
				return (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
			case 'db_auto_drafts':
				return (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
			case 'db_trashed_posts':
				return (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_status = 'trash'" );
			case 'db_spam_comments':
				return (int) $wpdb->get_var( "SELECT COUNT(comment_ID) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );
			case 'db_trashed_comments':
				return (int) $wpdb->get_var( "SELECT COUNT(comment_ID) FROM {$wpdb->comments} WHERE comment_approved IN ('trash', 'post-trashed')" );
			case 'db_transients':
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(option_id) FROM {$wpdb->options} WHERE (option_name LIKE %s OR option_name LIKE %s) AND option_name NOT LIKE %s AND option_name NOT LIKE %s",
						$wpdb->esc_like( '_transient_' ) . '%',
						$wpdb->esc_like( '_site_transient_' ) . '%',
						$wpdb->esc_like( '_transient_timeout_' ) . '%',
						$wpdb->esc_like( '_site_transient_timeout_' ) . '%'
					)
				);
			case 'db_optimize_tables':
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(table_name) FROM information_schema.tables WHERE table_schema = %s AND Engine <> 'InnoDB' AND data_free > 0",
						DB_NAME
					)
				);
		}

		return 0;
	}

	/**
	 * Удаляет мусор по списку пунктов.
	 *
	 * @param string[] $items Пункты очистки.
	 * @return array<string, int> Сколько удалено по каждому пункту.
	 */
	public static function cleanup( array $items ): array {
		$result = array();
		foreach ( array_intersect( array_keys( self::types() ), $items ) as $item ) {
			$result[ $item ] = self::cleanup_item( $item );
		}

		return $result;
	}

	/**
	 * Текст вида «12 ревизий».
	 *
	 * @param string $type  Тип.
	 * @param int    $count Количество.
	 */
	public static function count_text( string $type, int $count ): string {
		$types = self::types();
		$forms = $types[ $type ]['forms'] ?? array( 'элемент', 'элемента', 'элементов' );

		return number_format_i18n( $count ) . ' ' . self::plural( $count, $forms );
	}

	/**
	 * Выполняет один пункт очистки.
	 *
	 * @param string $item Тип.
	 */
	private static function cleanup_item( string $item ): int {
		global $wpdb;

		$deleted = 0;

		switch ( $item ) {
			case 'db_revisions':
				$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' LIMIT " . self::LIMIT );
				foreach ( $ids as $id ) {
					$deleted += wp_delete_post_revision( (int) $id ) instanceof \WP_Post ? 1 : 0;
				}
				break;
			case 'db_auto_drafts':
				$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft' LIMIT " . self::LIMIT );
				foreach ( $ids as $id ) {
					$deleted += wp_delete_post( (int) $id, true ) instanceof \WP_Post ? 1 : 0;
				}
				break;
			case 'db_trashed_posts':
				$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash' LIMIT " . self::LIMIT );
				foreach ( $ids as $id ) {
					$deleted += wp_delete_post( (int) $id, true ) instanceof \WP_Post ? 1 : 0;
				}
				break;
			case 'db_spam_comments':
				$ids = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'spam' LIMIT " . self::LIMIT );
				foreach ( $ids as $id ) {
					$deleted += wp_delete_comment( (int) $id, true ) ? 1 : 0;
				}
				break;
			case 'db_trashed_comments':
				$ids = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved IN ('trash', 'post-trashed') LIMIT " . self::LIMIT );
				foreach ( $ids as $id ) {
					$deleted += wp_delete_comment( (int) $id, true ) ? 1 : 0;
				}
				break;
			case 'db_transients':
				$names = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT option_name FROM {$wpdb->options} WHERE (option_name LIKE %s OR option_name LIKE %s) AND option_name NOT LIKE %s AND option_name NOT LIKE %s LIMIT " . self::LIMIT,
						$wpdb->esc_like( '_transient_' ) . '%',
						$wpdb->esc_like( '_site_transient_' ) . '%',
						$wpdb->esc_like( '_transient_timeout_' ) . '%',
						$wpdb->esc_like( '_site_transient_timeout_' ) . '%'
					)
				);
				foreach ( $names as $name ) {
					if ( str_starts_with( $name, '_site_transient_' ) ) {
						$deleted += delete_site_transient( substr( $name, strlen( '_site_transient_' ) ) ) ? 1 : 0;
					} else {
						$deleted += delete_transient( substr( $name, strlen( '_transient_' ) ) ) ? 1 : 0;
					}
				}
				break;
			case 'db_optimize_tables':
				$tables = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT table_name FROM information_schema.tables WHERE table_schema = %s AND Engine <> 'InnoDB' AND data_free > 0",
						DB_NAME
					)
				);
				foreach ( $tables as $table ) {
					$safe = str_replace( '`', '``', $table );
					$deleted += false !== $wpdb->query( "OPTIMIZE TABLE `{$safe}`" ) && '' === (string) $wpdb->last_error ? 1 : 0;
				}
				break;
		}

		return $deleted;
	}

	/**
	 * Имя расписания для выбранной частоты.
	 */
	private static function recurrence(): string {
		$frequency = (string) Settings::get( 'db_frequency' );

		if ( 'daily' === $frequency ) {
			return 'daily';
		}
		if ( 'monthly' === $frequency ) {
			return 'aliprofi_monthly';
		}

		return 'aliprofi_weekly';
	}

	/**
	 * Русская форма слова по числу.
	 *
	 * @param int      $count Число.
	 * @param string[] $forms Формы: 1, 2-4, 5+.
	 */
	private static function plural( int $count, array $forms ): string {
		$count = abs( $count ) % 100;
		$tail  = $count % 10;

		if ( $count > 10 && $count < 20 ) {
			return $forms[2];
		}
		if ( $tail > 1 && $tail < 5 ) {
			return $forms[1];
		}
		if ( 1 === $tail ) {
			return $forms[0];
		}

		return $forms[2];
	}
}
