<?php
/**
 * LCP-оптимизация изображений: снятие lazy у первого крупного кадра и размеры без CLS.
 *
 * @package Aliprofi_Optimizer
 */

namespace AliProfi\Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Изображения в контенте и вложения.
 */
final class Images {

	/**
	 * Исключения (в нижнем регистре).
	 *
	 * @var string[]
	 */
	private array $exclude = array();

	/**
	 * Уже обработано первое крупное изображение контента.
	 */
	private bool $first_large = false;

	/**
	 * Кэш размеров вложений.
	 *
	 * @var array<int, array{0:int,1:int}|null>
	 */
	private array $dims = array();

	/**
	 * Подключение модуля.
	 */
	public function run(): void {
		$lines = preg_split( '/\r\n|\r|\n/', (string) Settings::get( 'lazy_exclude' ) );
		foreach ( $lines as $line ) {
			$line = strtolower( trim( $line ) );
			if ( '' !== $line ) {
				$this->exclude[] = $line;
			}
		}

		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'attachment_attrs' ), 10, 3 );
		add_filter( 'the_content', array( $this, 'content' ), 20 );
	}

	/**
	 * Атрибуты изображений из шаблонов (логотип, hero и т.п.).
	 *
	 * @param array<string,string> $attr       Атрибуты.
	 * @param \WP_Post             $attachment Вложение.
	 * @param string|int[]         $size       Размер.
	 * @return array<string,string>
	 */
	public function attachment_attrs( $attr, $attachment, $size ): array {
		$attr = is_array( $attr ) ? $attr : array();
		$hay  = strtolower( ( isset( $attr['class'] ) ? $attr['class'] : '' ) . ' ' . ( isset( $attr['src'] ) ? basename( (string) wp_parse_url( (string) $attr['src'], PHP_URL_PATH ) ) : '' ) );

		if ( $this->is_excluded( $hay ) ) {
			unset( $attr['loading'] );
			$attr['fetchpriority'] = 'high';
		}

		return $attr;
	}

	/**
	 * Обработка картинок в контенте записи.
	 *
	 * @param string $content Контент.
	 */
	public function content( $content ): string {
		$content = (string) $content;
		if ( false === stripos( $content, '<img' ) ) {
			return $content;
		}

		$this->first_large = false;
		$result            = preg_replace_callback( '#<img\b[^>]*>#i', array( $this, 'process_img' ), $content );

		return is_string( $result ) ? $result : $content;
	}

	/**
	 * Обработка одного тега img.
	 *
	 * @param array<int, string> $match Совпадение.
	 */
	private function process_img( array $match ): string {
		$tag = $match[0];
		if ( preg_match( '#\bsrc\s*=\s*("|\')?data:#i', $tag ) ) {
			return $tag;
		}

		$classes  = $this->attr( $tag, 'class' );
		$hay      = strtolower( $classes . ' ' . $this->src_name( $tag ) );
		$excluded = $this->is_excluded( $hay );

		$priority = $excluded;
		if ( ! $priority && ! $this->first_large && $this->is_large( $tag, $classes ) ) {
			$priority          = true;
			$this->first_large = true;
		}

		if ( $priority ) {
			$tag = (string) preg_replace( '#\s+loading\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $tag );
			$tag = (string) preg_replace( '#\s+fetchpriority\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $tag );
			$tag = (string) preg_replace( '/<img\b/i', '<img fetchpriority="high"', $tag, 1 );
		}

		if ( Settings::is_on( 'img_dims' ) ) {
			$tag = $this->add_dimensions( $tag );
		}

		return $tag;
	}

	/**
	 * Крупное ли изображение (эвристика без БД).
	 *
	 * @param string $tag     Тег.
	 * @param string $classes Классы.
	 */
	private function is_large( string $tag, string $classes ): bool {
		$classes = strtolower( $classes );
		foreach ( array( 'emoji', 'avatar', 'smiley', 'wp-smiley', 'icon' ) as $skip ) {
			if ( str_contains( $classes, $skip ) ) {
				return false;
			}
		}

		$width = $this->attr( $tag, 'width' );
		if ( '' !== $width && (int) $width > 0 && (int) $width < 250 ) {
			return false;
		}

		return true;
	}

	/**
	 * Добавляет недостающие width/height из метаданных вложения.
	 *
	 * @param string $tag Тег.
	 */
	private function add_dimensions( string $tag ): string {
		$classes = $this->attr( $tag, 'class' );
		if ( ! preg_match( '/\bwp-image-(\d+)\b/', $classes, $id_match ) ) {
			return $tag;
		}

		$id   = (int) $id_match[1];
		$meta = $this->meta_dims( $id );
		if ( ! $meta ) {
			return $tag;
		}

		$width  = $this->attr( $tag, 'width' );
		$height = $this->attr( $tag, 'height' );
		$width  = (int) $width;
		$height = (int) $height;
		$ratio  = $meta[0] > 0 ? $meta[1] / $meta[0] : 0;

		if ( $width > 0 && $height > 0 ) {
			return $tag;
		}

		if ( $width <= 0 ) {
			$width = $this->srcset_width( $tag );
			if ( $width <= 0 ) {
				$width = $meta[0];
			}
		}
		if ( $height <= 0 ) {
			$height = $ratio > 0 ? (int) round( $width * $ratio ) : $meta[1];
		}
		if ( $width <= 0 || $height <= 0 ) {
			return $tag;
		}

		$add = '';
		if ( '' === $this->attr( $tag, 'width' ) ) {
			$add .= ' width="' . $width . '"';
		}
		if ( '' === $this->attr( $tag, 'height' ) ) {
			$add .= ' height="' . $height . '"';
		}
		if ( '' === $add ) {
			return $tag;
		}

		return (string) preg_replace( '/\s*(\/?)>$/', $add . '$1>', $tag, 1 );
	}

	/**
	 * Размеры оригинала вложения (кэшируются в запросе).
	 *
	 * @param int $id ID вложения.
	 * @return array{0:int,1:int}|null
	 */
	private function meta_dims( int $id ): ?array {
		if ( ! array_key_exists( $id, $this->dims ) ) {
			$meta                = wp_get_attachment_metadata( $id );
			$this->dims[ $id ] = ( is_array( $meta ) && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) )
				? array( (int) $meta['width'], (int) $meta['height'] )
				: null;
		}

		return $this->dims[ $id ];
	}

	/**
	 * Наибольшая ширина из srcset.
	 *
	 * @param string $tag Тег.
	 */
	private function srcset_width( string $tag ): int {
		$srcset = $this->attr( $tag, 'srcset' );
		if ( '' === $srcset ) {
			return 0;
		}

		$max = 0;
		foreach ( explode( ',', $srcset ) as $candidate ) {
			if ( preg_match( '/(\d+)w\s*$/', trim( $candidate ), $match ) ) {
				$max = max( $max, (int) $match[1] );
			}
		}

		return $max;
	}

	/**
	 * Значение атрибута тега.
	 *
	 * @param string $tag  Тег.
	 * @param string $name Атрибут.
	 */
	private function attr( string $tag, string $name ): string {
		if ( ! preg_match( '#\s' . preg_quote( $name, '#' ) . '\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', $tag, $match ) ) {
			return '';
		}

		$value = trim( $match[1] );
		if ( ( str_starts_with( $value, '"' ) && str_ends_with( $value, '"' ) ) || ( str_starts_with( $value, "'" ) && str_ends_with( $value, "'" ) ) ) {
			$value = substr( $value, 1, -1 );
		}

		return html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Имя файла из src.
	 *
	 * @param string $tag Тег.
	 */
	private function src_name( string $tag ): string {
		$src = $this->attr( $tag, 'src' );

		return '' !== $src ? basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) : '';
	}

	/**
	 * Проверка исключений.
	 *
	 * @param string $haystack Строка для поиска (нижний регистр).
	 */
	private function is_excluded( string $haystack ): bool {
		foreach ( $this->exclude as $needle ) {
			if ( str_contains( $haystack, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
