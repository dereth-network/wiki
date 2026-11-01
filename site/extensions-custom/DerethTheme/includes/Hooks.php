<?php

/**
 * Loads DerethTheme's styles and script on pages rendered with Citizen, and
 * supplies the text of Citizen's footer. Other skins (e.g. legacy Vector via
 * ?useskin=vector) are left untouched.
 */
class DerethThemeHooks {

	/**
	 * Citizen's footer messages, kept here (in git) instead of as on-wiki
	 * MediaWiki: pages. Keys are message names with the first letter
	 * uppercased, as MessagesPreLoad passes them.
	 */
	private const MESSAGES = [
		'Citizen-footer-desc' => 'A read-only archive of the Asheron\'s Call Community Wiki '
			. '(ACCPP Wiki), hosted by dereth.network. Not affiliated with ACCPP.',
		'Citizen-footer-tagline' => 'Asheron\'s Call is a trademark of its owners; this is an '
			. 'unofficial fan archive.',
	];

	/**
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public static function onBeforePageDisplay( $out, $skin ): void {
		if ( $skin->getSkinName() !== 'citizen' ) {
			return;
		}
		// Styles go in the <head> so the dark colours apply before first paint.
		$out->addModuleStyles( 'ext.derethTheme.styles' );
		$out->addModules( 'ext.derethTheme' );
	}

	/**
	 * @param string $title message key, e.g. "Citizen-footer-desc" (with a
	 *  "/xx" suffix for languages other than the content language)
	 * @param string|false &$message
	 * @param string $code language code
	 */
	public static function onMessagesPreLoad( $title, &$message, $code ): void {
		if ( isset( self::MESSAGES[$title] ) ) {
			$message = self::MESSAGES[$title];
		}
	}
}
