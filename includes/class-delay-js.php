<?php
/**
 * Отложенная загрузка JavaScript до первого действия пользователя.
 *
 * @package Aliprofi_Optimizer
 */

namespace AliProfi\Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Delay JavaScript Execution: задерживаются все скрипты, кроме списка исключений.
 */
final class DelayJs {

	/**
	 * Исключения (в нижнем регистре).
	 *
	 * @var string[]
	 */
	private array $exclusions = array();

	/**
	 * Были ли изменены скрипты в текущем буфере.
	 */
	private bool $touched = false;

	/**
	 * Читает настройки.
	 */
	public function __construct() {
		$lines = preg_split( '/\r\n|\r|\n/', (string) Settings::get( 'delay_exclusions' ) );
		foreach ( $lines as $line ) {
			$line = strtolower( trim( $line ) );
			if ( '' !== $line ) {
				$this->exclusions[] = $line;
			}
		}

		if ( Settings::is_on( 'delay_safe_mode' ) ) {
			$this->exclusions = array_merge( $this->exclusions, array( '/wp-content/', '/wp-includes/', 'js-before', 'js-after', 'jquery' ) );
		}
	}

	/**
	 * Включает буферизацию на template_redirect.
	 */
	public function run(): void {
		add_action( 'template_redirect', array( $this, 'start' ), 1 );
	}

	/**
	 * Старт буфера.
	 */
	public function start(): void {
		ob_start( array( $this, 'filter' ) );
	}

	/**
	 * Обработка итогового HTML.
	 *
	 * @param string $html HTML страницы.
	 */
	public function filter( $html ): string {
		$html = (string) $html;
		if ( '' === $html || false === stripos( $html, '<script' ) ) {
			return $html;
		}

		$this->touched = false;
		$result        = preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script>#is',
			array( $this, 'rewrite' ),
			$html
		);

		if ( ! is_string( $result ) ) {
			return $html;
		}

		return $this->touched ? $this->inject_loader( $result ) : $result;
	}

	/**
	 * Переписывает один тег script, если он не исключён.
	 *
	 * @param array<int, string> $match Совпадение.
	 */
	private function rewrite( array $match ): string {
		$attrs = $match[1];
		$body  = $match[2];

		if ( false !== stripos( $attrs, 'data-delayed-src' ) || false !== stripos( $attrs, 'aliprofi-optimizer-delay' ) || false !== stripos( $attrs, 'data-no-delay' ) ) {
			return $match[0];
		}

		$type     = '';
		$has_type = false;
		if ( preg_match( '#(?<![-\w])type\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', $attrs, $tm ) ) {
			$has_type = true;
			$raw      = trim( $tm[1] );
			if ( ( str_starts_with( $raw, '"' ) && str_ends_with( $raw, '"' ) ) || ( str_starts_with( $raw, "'" ) && str_ends_with( $raw, "'" ) ) ) {
				$raw = substr( $raw, 1, -1 );
			}
			$type = strtolower( trim( $raw ) );
		}

		$js_types = array( '', 'text/javascript', 'application/javascript', 'text/ecmascript', 'application/ecmascript', 'module' );
		if ( $has_type && ! in_array( $type, $js_types, true ) ) {
			return $match[0];
		}

		$haystack = strtolower( $attrs . ' ' . $body );
		foreach ( $this->exclusions as $needle ) {
			if ( str_contains( $haystack, $needle ) ) {
				return $match[0];
			}
		}

		$src = '';
		if ( preg_match( '#(?<![-\w])src\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', $attrs, $sm ) ) {
			$src = trim( $sm[1] );
			if ( ( str_starts_with( $src, '"' ) && str_ends_with( $src, '"' ) ) || ( str_starts_with( $src, "'" ) && str_ends_with( $src, "'" ) ) ) {
				$src = substr( $src, 1, -1 );
			}
		}
		if ( '' === $src && '' === trim( $body ) ) {
			return $match[0];
		}

		$new = (string) preg_replace( '#\s*(?<![-\w])src\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $attrs, 1 );
		if ( $has_type ) {
			$new = (string) preg_replace( '#(?<![-\w])type\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', 'type="delayed-script"', $new, 1 );
		} else {
			$new .= ' type="delayed-script"';
		}
		if ( 'module' === $type ) {
			$new .= ' data-delayed-type="module"';
		}
		if ( '' !== $src ) {
			$new .= ' data-delayed-src="' . esc_attr( html_entity_decode( $src, ENT_QUOTES, 'UTF-8' ) ) . '"';
		}

		$this->touched = true;

		return '<script' . $new . '>' . $body . '</script>';
	}

	/**
	 * Вставляет загрузчик перед закрывающим body.
	 *
	 * @param string $html HTML.
	 */
	private function inject_loader( string $html ): string {
		$loader = '<script id="aliprofi-optimizer-delay">' . $this->loader_js() . '</script>';
		$pos    = strripos( $html, '</body>' );

		if ( false === $pos ) {
			return $html . $loader;
		}

		return substr( $html, 0, $pos ) . $loader . substr( $html, $pos );
	}

	/**
	 * Минифицированный загрузчик (менее 1 КБ).
	 */
	private function loader_js(): string {
		$timeout = max( 0, min( 60000, (int) Settings::get( 'delay_timeout' ) ) );

		$js = '(function(){var d=document,t=false,e=["mousemove","scroll","touchstart","keydown","click"],w=0,f=function(){if(t)return;t=true;if(w)clearTimeout(w);d.querySelectorAll("script[type=delayed-script]").forEach(function(o){var s=d.createElement("script"),u=o.getAttribute("data-delayed-src");if(u)s.async=false;for(var i=0;i<o.attributes.length;i++){var a=o.attributes[i],n=a.name;if(n!="type"&&n!="src"&&n!="data-delayed-src"&&n!="data-delayed-type")s.setAttribute(n,a.value)}s.type=o.getAttribute("data-delayed-type")||"text/javascript";if(u)s.src=u;else s.text=o.text||o.textContent;o.parentNode.replaceChild(s,o)})};e.forEach(function(n){addEventListener(n,f,{passive:true,once:true})});if(' . $timeout . '>0)w=setTimeout(f,' . $timeout . ')})();';

		return $js;
	}
}
