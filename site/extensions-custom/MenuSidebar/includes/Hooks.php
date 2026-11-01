<?php
/**
 * MenuSidebar: renders MediaWiki:MenuSidebar as hover-flyout menus.
 *
 * Changes in 0.2:
 *  * Use "-" as list item to create a separator
 * Changes in 0.3:
 *  * Added CSS class item# and even/odd to list items to make styling easier
 * Changes in 0.4:
 *  * Added a small arrow ">" for each menu (with subitems) displayed on the right.
 * Changes in 0.4.1:
 *  * BUGFIX: last </li> was missing
 * Changes in 0.5 (ACCPP modern-stack port):
 *  * Sections are injected via the SkinAfterPortlet hook, keyed by section
 *    title, so each MediaWiki:MenuSidebar section lands after the
 *    MediaWiki:Sidebar heading of the same name. (Modern skins discard the
 *    raw-HTML sidebar entries the old SkinBuildSidebar approach produced.)
 * Changes in 0.6 (MediaWiki 1.43 port):
 *  * extension.json registration, injected services instead of the
 *    $wgParser/$wgTitle/$wgUser globals, and a fresh Parser instance so
 *    menu parsing never re-enters the page's own parse.
 *  * The built menu HTML is cached per MediaWiki:MenuSidebar revision (and
 *    user language) instead of being rebuilt on every page view; saving
 *    MediaWiki:MenuSidebar purges it.
 *  * Parsed items ($wgParseListItems) are parsed in the context of
 *    MediaWiki:MenuSidebar rather than the page being viewed, so the cached
 *    menu is the same on every page.
 */

namespace MediaWiki\Extension\MenuSidebar;

use MediaWiki\Content\TextContent;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Parser\ParserFactory;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Skins\Hook\SkinAfterPortletHook;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\Title;
use Skin;
use Wikimedia\ObjectCache\WANObjectCache;

class Hooks implements SkinAfterPortletHook, BeforePageDisplayHook, PageSaveCompleteHook {

	private RevisionLookup $revisionLookup;
	private ParserFactory $parserFactory;
	private WANObjectCache $cache;

	/** @var array<string,string>|null sections built for this request */
	private ?array $sections = null;

	public function __construct(
		RevisionLookup $revisionLookup,
		ParserFactory $parserFactory,
		WANObjectCache $cache
	) {
		$this->revisionLookup = $revisionLookup;
		$this->parserFactory = $parserFactory;
		$this->cache = $cache;
	}

	/** @inheritDoc */
	public function onBeforePageDisplay( $out, $skin ): void {
		$out->addModuleStyles( 'ext.menuSidebar.styles' );
	}

	/** @inheritDoc */
	public function onSkinAfterPortlet( $skin, $portletName, &$html ) {
		$sections = $this->getSections( $skin );
		if ( array_key_exists( $portletName, $sections ) ) {
			$html .= $sections[$portletName];
		}
	}

	/** @inheritDoc */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
		$title = $wikiPage->getTitle();
		if ( $title->getNamespace() === NS_MEDIAWIKI && $title->getDBkey() === 'MenuSidebar' ) {
			$this->cache->touchCheckKey( $this->cache->makeKey( 'menusidebar-check' ) );
		}
	}

	/**
	 * Splits MediaWiki:MenuSidebar into named sections, keyed by the trimmed
	 * text of each top-level "*Heading" line (the trailing, title-less bundle
	 * at the end of the page -- Recent Changes / Random Page / etc -- keys
	 * under "").
	 *
	 * @return array<string,string> section title => HTML
	 */
	private function getSections( Skin $skin ): array {
		if ( $this->sections !== null ) {
			return $this->sections;
		}

		$title = Title::makeTitle( NS_MEDIAWIKI, 'MenuSidebar' );
		$revRecord = $this->revisionLookup->getRevisionByTitle( $title );
		if ( !$revRecord ) {
			$this->sections = [];
			return $this->sections;
		}

		$parseListItems = (bool)$skin->getConfig()->get( 'ParseListItems' );
		$lang = $skin->getLanguage()->getCode();

		$this->sections = $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'menusidebar', $revRecord->getId(), $lang, (int)$parseListItems ),
			WANObjectCache::TTL_DAY,
			function () use ( $revRecord, $title, $parseListItems, $skin ) {
				return $this->buildSections( $revRecord, $title, $parseListItems, $skin );
			},
			[ 'checkKeys' => [ $this->cache->makeKey( 'menusidebar-check' ) ] ]
		);
		return $this->sections;
	}

	private function buildSections(
		RevisionRecord $revRecord, Title $title, bool $parseListItems, Skin $skin
	): array {
		$content = $revRecord->getContent( SlotRecord::MAIN, RevisionRecord::RAW );
		$text = $content instanceof TextContent ? $content->getText() : '';
		$lines = explode( "\n", $text );

		$parser = null;
		$opt = null;
		if ( $parseListItems ) {
			$parser = $this->parserFactory->create();
			$opt = ParserOptions::newFromContext( $skin->getContext() );
		}

		$sections = [];
		$sectionTitle = '';
		for ( $i = 0; $i < count( $lines ); $i++ ) {
			$line = $lines[$i];
			if ( strpos( $line, '**' ) === 0 && $i > 0 ) {
				// entry in a deeper level
				$sections[$sectionTitle] = '
			<div class="menuSidebar">
				<ul>
					' . $this->buildList( $lines, $i, 1, $parser, $opt, $title ) . '
				</ul>
			</div>
			';
				$i--;
			} else {
				// use entry as section title
				$sectionTitle = trim( $line, '* ' );
			}
		}
		return $sections;
	}

	private function buildList(
		array $lines, int &$i, int $level, $parser, ?ParserOptions $opt, Title $contextTitle
	): string {
		$content = '';
		$closeLI = false;
		$itemCount = 0;
		for ( ; $i < count( $lines ); $i++ ) {
			$itemCount++;

			$class = "item{$itemCount}";
			$class .= ( $itemCount % 2 == 0 ? ' even' : ' odd' );

			$line = substr( $lines[$i], $level );

			if ( strpos( $line, '**' ) === 0 ) {
				// entry in a deeper level: add the flyout indicator to the
				// parent link, matching the live site
				$content = rtrim( $content );
				if ( strrpos( $content, '</a>' ) === strlen( $content ) - 4 ) {
					$content = substr( $content, 0, -4 ) . "<em>\u{203A}\u{203A}</em></a>";
				}

				$content .= '
				<div><ul>
					' . $this->buildList( $lines, $i, $level + 1, $parser, $opt, $contextTitle ) . '
				</ul></div>
				';
				$i--;
				$itemCount--;
			} elseif ( strpos( $line, '*' ) === 0 ) {
				// entry in this level
				if ( $closeLI ) {
					$content .= '</li>';
					$closeLI = false;
				}
				if ( $parser ) {
					$rawText = $parser->parse( trim( $line, '* ' ), $contextTitle, $opt, true, true )
						->runOutputPipeline( $opt, [ 'unwrap' => true ] )
						->getContentHolderText();
					if ( preg_match( '/<p>(.*)<\/p>/s', $rawText, $m ) ) {
						$text = trim( $m[1] );
					} else {
						$text = trim( $rawText );
					}

					if ( trim( $text ) == '-' ) {
						$class .= ' separator';
						$text = '';
					}
					if ( strpos( $text, '<a' ) !== 0 ) {
						// needed to display plain text correctly
						$text = '<a>' . $text . '</a>';
					}

					$content .= "<li class=\"$class\">$text";
					$closeLI = true;
				} elseif ( strpos( $line, '|' ) !== false ) {
					$parts = array_map( 'trim', explode( '|', trim( $line, '* ' ), 2 ) );
					$link = wfMessage( $parts[0] )->inContentLanguage()->text();
					if ( $link == '-' ) {
						continue;
					}

					$text = wfMessage( $parts[1] )->text();
					if ( wfMessage( $parts[1] )->inContentLanguage()->isBlank() ) {
						$text = $parts[1];
					}
					if ( wfMessage( $parts[0] )->inContentLanguage()->isBlank() ) {
						$link = $parts[0];
					}

					if ( preg_match( '/^(?:' . wfUrlProtocols() . ')/', $link ) ) {
						$href = $link;
					} else {
						$title = Title::newFromText( $link );
						if ( $title ) {
							$href = $title->fixSpecialName()->getLocalURL();
						} else {
							$href = 'INVALID-TITLE';
						}
					}
					$href = htmlspecialchars( $href );
					$text = htmlspecialchars( $text );
					$content .= "<li class=\"$class\"><a href=\"$href\">$text</a>";
					$closeLI = true;
				} else {
					if ( trim( $line ) == '-' ) {
						$class = ' separator';
					}
					$text = htmlspecialchars( trim( $line, '* ' ) );
					$content .= "<li class=\"$class\"><a>$text</a>";
					$closeLI = true;
				}
			} else {
				if ( $closeLI ) {
					$content .= '</li>';
					$closeLI = false;
				}
				break;
			}
		}
		if ( $closeLI ) {
			$content .= '</li>';
		}
		return $content;
	}
}
