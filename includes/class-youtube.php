<?php
/**
 * Лёгкий фасад для YouTube вместо тяжёлого iframe.
 *
 * @package Aliprofi_Optimizer
 */

namespace AliProfi\Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Превью YouTube с загрузкой плеера по клику.
 */
final class YouTube {

	/**
	 * Был ли выведен хотя бы один фасад.
	 */
	private bool $used = false;

	/**
	 * Подключение модуля.
	 */
	public function run(): void {
		add_filter( 'the_content', array( $this, 'content' ), 30 );
		add_filter( 'embed_oembed_html', array( $this, 'oembed' ), 10, 4 );
		add_action( 'wp_head', array( $this, 'styles' ), 5 );
		add_action( 'wp_footer', array( $this, 'script' ), 99 );
	}

	/**
	 * Замена iframe в контенте.
	 *
	 * @param string $html Контент.
	 */
	public function content( $html ): string {
		return $this->replace( (string) $html );
	}

	/**
	 * Замена iframe в oEmbed.
	 *
	 * @param string $html    HTML.
	 * @param string $url     URL.
	 * @param array  $attr    Атрибуты.
	 * @param int    $post_id ID записи.
	 */
	public function oembed( $html, $url = '', $attr = array(), $post_id = 0 ): string {
		return $this->replace( (string) $html );
	}

	/**
	 * Поиск и подмена YouTube-iframe.
	 *
	 * @param string $html HTML.
	 */
	public function replace( string $html ): string {
		if ( false === stripos( $html, '<iframe' ) || false === stripos( $html, 'youtu' ) ) {
			return $html;
		}

		$result = preg_replace_callback(
			'#<iframe\b([^>]*)>(.*?)</iframe>#is',
			array( $this, 'rewrite' ),
			$html
		);

		return is_string( $result ) ? $result : $html;
	}

	/**
	 * Переписывает один iframe.
	 *
	 * @param array<int, string> $match Совпадение.
	 */
	private function rewrite( array $match ): string {
		$attrs = $match[1];
		if ( ! preg_match( '#(?<![-\w])src\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', $attrs, $src_match ) ) {
			return $match[0];
		}

		$src = trim( $src_match[1] );
		if ( str_starts_with( $src, '"' ) || str_starts_with( $src, "'" ) ) {
			$src = substr( $src, 1, -1 );
		}
		$src = html_entity_decode( $src, ENT_QUOTES, 'UTF-8' );

		if ( ! preg_match( '#(?:youtube(?:-nocookie)?\.com/(?:embed/|v/|watch\?v=|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,20})#i', $src, $id_match ) ) {
			return $match[0];
		}

		$id    = $id_match[1];
		$title = $this->attr( $attrs, 'title' );
		if ( '' === $title ) {
			$title = 'YouTube';
		}

		$this->used = true;

		return '<div class="yt-facade" data-id="' . esc_attr( $id ) . '" data-title="' . esc_attr( $title ) . '" role="button" tabindex="0" aria-label="Воспроизвести видео">'
			. '<img class="yt-facade__img" src="' . esc_url( 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg' ) . '" alt="" width="480" height="360" loading="lazy" decoding="async">'
			. '<span class="yt-facade__btn" aria-hidden="true"><svg viewBox="0 0 68 48" width="68" height="48" focusable="false"><path d="M66.5 7.7a8 8 0 0 0-5.6-5.6C56 .7 34 .7 34 .7s-22 0-26.9 1.4A8 8 0 0 0 1.5 7.7C0 12.6 0 24 0 24s0 11.4 1.5 16.3a8 8 0 0 0 5.6 5.6C12 47.3 34 47.3 34 47.3s22 0 26.9-1.4a8 8 0 0 0 5.6-5.6C68 35.4 68 24 68 24s0-11.4-1.5-16.3z" fill="#f00"/><path d="M27 34l18-10-18-10z" fill="#fff"/></svg></span>'
			. '</div>';
	}

	/**
	 * Минимальные стили фасада.
	 */
	public function styles(): void {
		echo '<style id="aliprofi-optimizer-yt">.yt-facade{position:relative;display:block;width:100%;aspect-ratio:16/9;background:#000;cursor:pointer;overflow:hidden}.yt-facade__img{width:100%;height:100%;object-fit:cover;display:block;border:0}.yt-facade__btn{position:absolute;inset:0;display:flex;align-items:center;justify-content:center}.yt-facade__btn svg{width:68px;height:48px;filter:drop-shadow(0 1px 4px rgba(0,0,0,.5))}.yt-facade__frame{display:block;width:100%;aspect-ratio:16/9;border:0}</style>' . "\n";
	}

	/**
	 * Скрипт подмены фасада на плеер (только если фасад есть на странице).
	 */
	public function script(): void {
		if ( ! $this->used ) {
			return;
		}
		?>
		<script id="aliprofi-optimizer-yt-js">(function(){function p(f){var i=document.createElement("iframe");i.src="https://www.youtube.com/embed/"+f.getAttribute("data-id")+"?autoplay=1";i.title=f.getAttribute("data-title")||"YouTube";i.allow="autoplay; encrypted-media; picture-in-picture; fullscreen";i.setAttribute("allowfullscreen","");i.className="yt-facade__frame";f.parentNode.replaceChild(i,f)}function h(e){var f=e.target.closest?e.target.closest(".yt-facade"):null;if(!f)return;e.preventDefault();p(f)}document.addEventListener("click",h);document.addEventListener("keydown",function(e){if(e.key==="Enter"||e.key===" ")h(e)})})();</script>
		<?php
	}

	/**
	 * Значение атрибута.
	 *
	 * @param string $attrs Атрибуты.
	 * @param string $name  Имя.
	 */
	private function attr( string $attrs, string $name ): string {
		if ( ! preg_match( '#\s' . preg_quote( $name, '#' ) . '\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', $attrs, $match ) ) {
			return '';
		}

		$value = trim( $match[1] );
		if ( ( str_starts_with( $value, '"' ) && str_ends_with( $value, '"' ) ) || ( str_starts_with( $value, "'" ) && str_ends_with( $value, "'" ) ) ) {
			$value = substr( $value, 1, -1 );
		}

		return html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
	}
}
