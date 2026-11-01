<?php
/**
 * <youtube>VIDEO_ID</youtube>  ->  embedded YouTube player + "Watch on YouTube" link.
 *
 * Both acpedia.org and asheron.fandom.com use this tag (Fandom via its
 * YouTube/EmbedVideo extensions). Without a handler, stock MediaWiki prints the
 * tag as literal text. This is a deliberately small, dependency-free
 * replacement: the player is an <iframe> pointing at youtube-nocookie.com, so
 * the video streams from YouTube when the page is viewed. No video data is
 * stored in this package.
 *
 * Accepted forms (all seen in the wiki content):
 *   <youtube>0N_LYEdTAGo</youtube>
 *   <youtube width="500" height="300">0N_LYEdTAGo</youtube>
 *   <youtube>https://www.youtube.com/watch?v=0N_LYEdTAGo</youtube>
 *   <youtube>https://youtu.be/0N_LYEdTAGo</youtube>
 *   <youtube>PLxxxxxxxx...</youtube>            (playlist id)
 * Anything that doesn't contain a video/playlist id (e.g. the "VideoID"
 * placeholders on the how-to page) renders as a small note instead of a
 * broken player.
 */
class YouTubeTagHooks {

	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setHook( 'youtube', [ self::class, 'render' ] );
		return true;
	}

	/**
	 * @param string|null $input   text between the tags
	 * @param array $args          tag attributes (width, height)
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string HTML
	 */
	public static function render( $input, array $args, Parser $parser, PPFrame $frame ) {
		$raw = trim( html_entity_decode( (string)$input ) );

		// Playlist?
		if ( preg_match( '/(?:list=)?(PL[A-Za-z0-9_-]{16,})/', $raw, $m ) ) {
			$src = 'https://www.youtube-nocookie.com/embed/videoseries?list=' . $m[1];
			$watch = 'https://www.youtube.com/playlist?list=' . $m[1];
		} elseif ( preg_match( '/(?:v=|youtu\.be\/|embed\/|^|[^A-Za-z0-9_-])([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])/', $raw, $m ) ) {
			$src = 'https://www.youtube-nocookie.com/embed/' . $m[1];
			$watch = 'https://www.youtube.com/watch?v=' . $m[1];
		} else {
			return Html::element( 'span',
				[ 'class' => 'youtube-tag-invalid', 'style' => 'color:#777;font-style:italic;' ],
				'[YouTube video: no video id given' . ( $raw !== '' ? ' (' . mb_substr( $raw, 0, 40 ) . ')' : '' ) . ']'
			);
		}

		$width = isset( $args['width'] ) ? (int)$args['width'] : 0;
		$height = isset( $args['height'] ) ? (int)$args['height'] : 0;
		if ( $width < 100 ) {
			$width = 560;
		}
		if ( $height < 60 ) {
			$height = (int)round( $width * 9 / 16 );
		}

		$iframe = Html::element( 'iframe', [
			'src' => $src,
			'width' => $width,
			'height' => $height,
			'style' => 'max-width:100%;border:0;',
			'allow' => 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen',
			'allowfullscreen' => '',
			'loading' => 'lazy',
			'referrerpolicy' => 'strict-origin-when-cross-origin',
			'title' => 'YouTube video player',
		], '' );
		$link = Html::element( 'a', [ 'href' => $watch, 'class' => 'external', 'rel' => 'nofollow' ], 'Watch on YouTube' );

		return Html::rawElement( 'div',
			[ 'class' => 'youtube-tag', 'style' => 'display:inline-block;max-width:100%;margin:0.3em 0;' ],
			$iframe . Html::rawElement( 'div', [ 'style' => 'font-size:85%;' ], $link )
		);
	}
}
