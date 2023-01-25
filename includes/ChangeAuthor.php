<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * @file
 * @ingroup Extensions
 * @author Roan Kattouw <roan.kattouw@gmail.com>
 * @copyright Copyright © 2007 Roan Kattouw
 * @license http://www.gnu.org/copyleft/gpl.html GPL-3.0-or-later
 *
 * An extension that allows changing the author of a revision
 * Written for the Bokt Wiki <http://www.bokt.nl/wiki/> by Roan Kattouw <roan.kattouw@gmail.com>
 * For information how to install and use this extension, see the README file.
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use Wikimedia\AtEase\AtEase;

class ChangeAuthor extends SpecialPage {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'ChangeAuthor'/* class */, 'changeauthor'/* restriction */ );
	}

	/**
	 * Group this special page under the correct group in Special:SpecialPages
	 *
	 * @return string
	 */
	protected function getGroupName() {
		return 'pagetools';
	}

	/**
	 * @see https://phabricator.wikimedia.org/T123591
	 *
	 * @return string
	 */
	public function doesWrites() {
		return true;
	}

	/**
	 * Show the special page
	 *
	 * @param mixed|null $par Parameter passed to the page
	 */
	public function execute( $par ) {
		$out = $this->getOutput();
		$request = $this->getRequest();

		$this->setHeaders();

		// Check permissions
		$this->checkPermissions();

		// check if database is in read-only mode
		$this->checkReadOnly();

		$out->setPageTitle( $this->msg( 'changeauthor-title' ) );

		if ( $par !== null ) {
			$obj = $this->parseTitleOrRevID( $par );
			if ( $obj instanceof Title ) {
				if ( $obj->exists() ) {
					$out->addHTML( $this->buildRevisionList( $obj ) );
				} else {
					$out->addHTML( $this->buildInitialForm( $this->msg( 'changeauthor-nosuchtitle', $obj->getPrefixedText() )->text() ) );
				}
				return;
			} elseif ( $obj instanceof Revision ) {
				$out->addHTML( $this->buildOneRevForm( $obj ) );
				return;
			}
		}

		$action = $request->getVal( 'action' );
		if ( $request->wasPosted() && $action == 'change' ) {
			$arr = $this->parseChangeRequest();
			if ( !is_array( $arr ) ) {
				$targetPage = $request->getVal( 'targetpage' );
				if ( $targetPage !== null ) {
					$out->addHTML( $this->buildRevisionList( Title::newFromURL( $targetPage ), $arr ) );
					return;
				}
				$targetRev = $request->getVal( 'targetrev' );
				if ( $targetRev !== null ) {
					$out->addHTML( $this->buildOneRevForm( Revision::newFromId( $targetRev ), $arr ) );
					return;
				}
				$out->addHTML( $this->buildInitialForm() );
			} else {
				$this->changeRevAuthors( $arr, $request->getVal( 'comment' ) );
				$out->addWikiMsg( 'changeauthor-success' );
			}
			return;
		}
		if ( $request->wasPosted() && $action == 'list' ) {
			$obj = $this->parseTitleOrRevID( $request->getVal( 'pagename-revid' ) );
			if ( $obj instanceof Title ) {
				if ( $obj->exists() ) {
					$out->addHTML( $this->buildRevisionList( $obj ) );
				} else {
					$out->addHTML( $this->buildInitialForm( $this->msg( 'changeauthor-nosuchtitle', $obj->getPrefixedText() )->text() ) );
				}
			} elseif ( $obj instanceof Revision ) {
				$out->addHTML( $this->buildOneRevForm( $obj ) );
			}
			return;
		}
		$out->addHTML( $this->buildInitialForm() );
	}

	/**
	 * Parse what can be a revision ID or an article name
	 * @param mixed $str Revision ID or an article name
	 * @return Title or Revision object, or NULL
	 */
	private function parseTitleOrRevID( $str ) {
		$retval = false;
		if ( is_numeric( $str ) ) {
			$retval = Revision::newFromID( $str );
		}
		if ( !$retval ) {
			$retval = Title::newFromURL( $str );
		}
		return $retval;
	}

	/**
	 * Builds the form that asks for a page name or revid
	 *
	 * @param string $errMsg Error message
	 * @return string HTML
	 */
	private function buildInitialForm( $errMsg = '' ) {
		global $wgScript;
		$retval = Xml::openElement( 'form', [ 'method' => 'post', 'action' => $wgScript ] );
		$retval .= Html::hidden( 'title', $this->getPageTitle()->getPrefixedDBkey() );
		$retval .= Html::hidden( 'action', 'list' );
		$retval .= Xml::openElement( 'fieldset' );
		$retval .= Xml::element( 'legend', [], $this->msg( 'changeauthor-search-box' )->text() );
		$retval .= Xml::inputLabel( $this->msg( 'changeauthor-pagename-or-revid' )->text(),
				'pagename-revid', 'pagename-revid' );
		$retval .= Xml::submitButton( $this->msg( 'changeauthor-pagenameform-go' )->text() );
		if ( $errMsg != '' ) {
			$retval .= Xml::openElement( 'p' ) . Xml::openElement( 'b' );
			$retval .= Xml::element( 'font', [ 'color' => 'red' ], $errMsg );
			$retval .= Xml::closeElement( 'b' ) . Xml::closeElement( 'p' );
		}
		$retval .= Xml::closeElement( 'fieldset' );
		$retval .= Xml::closeElement( 'form' );
		return $retval;
	}

	/**
	 * Builds a line for revision $rev
	 * Helper to buildRevisionList() and buildOneRevForm()
	 *
	 * @param Revision $rev Revision object
	 * @param Title $title Title object
	 * @param bool $isFirst Set to true if $rev is the first revision
	 * @param bool $isLast Set to true if $rev is the last revision
	 * @return string HTML
	 */
	private function buildRevisionLine( $rev, $title, $isFirst = false, $isLast = false ) {
		$linkRenderer = $this->getLinkRenderer();
		// Build curlink
		if ( $isFirst ) {
			$curLink = $this->msg( 'cur' )->text();
		} else {
			$curLink = $linkRenderer->makeKnownLink(
				$title,
				$this->msg( 'cur' )->text(),
				[],
				[ 'oldid' => $rev->getId(), 'diff' => 'cur' ]
			);
		}

		if ( $isLast ) {
			$lastLink = $this->msg( 'last' )->text();
		} else {
			$lastLink = $linkRenderer->makeKnownLink(
				$title,
				$this->msg( 'last' )->text(),
				[],
				[
					'oldid' => 'prev',
					'diff' => $rev->getId()
				]
			);
		}

		// Build oldid link
		$date = $this->getLanguage()->timeanddate( wfTimestamp( TS_MW, $rev->getTimestamp() ), true );
		if ( RevisionRecord::userCanBitfield(
			$rev->getVisibility(),
			RevisionRecord::DELETED_TEXT,
			$this->getUser()
		) ) {
			$link = $linkRenderer->makeKnownLink( $title, $date, [], [ 'oldid' => $rev->getId() ] );
		} else {
			$link = $date;
		}

		// Build user textbox
		$userBox = Xml::input( "user-new-{$rev->getId()}", 50, $this->getRequest()->getVal( "user-{$rev->getId()}", $rev->getUserText() ) );
		$userText = Html::hidden( "user-old-{$rev->getId()}", $rev->getUserText() ) . $rev->getUserText();

		$size = $rev->getSize();
		if ( $size !== null ) {
			if ( $size == 0 ) {
				$stxt = $this->msg( 'historyempty' )->text();
			} else {
				$stxt = $this->msg( 'historysize', $this->getLanguage()->formatNum( $size ) )->text();
			}
		} else {
			$stxt = ''; // Stop PHP from whining about unset variables
		}
		$comment = MediaWikiServices::getInstance()->getCommentFormatter()
			->formatBlock( $rev->getComment(), $title );

		// Now put it all together
		return "<li>($curLink) ($lastLink) $link . . $userBox ($userText) $stxt $comment</li>\n";
	}

	/**
	 * Builds a form listing the last 50 revisions of $title that allows changing authors
	 *
	 * @param Title $title
	 * @param string $errMsg Error message
	 * @return string HTML
	 */
	private function buildRevisionList( $title, $errMsg = '' ) {
		global $wgScript;

		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			'revision',
			Revision::selectFields(),
			[ 'rev_page' => $title->getArticleID() ],
			__METHOD__,
			[ 'ORDER BY' => 'rev_timestamp DESC', 'LIMIT' => 50 ]
		);

		$revs = [];
		foreach ( $res as $r ) {
			$revs[] = new Revision( $r );
		}

		if ( $revs === [] ) {
			// That's *very* weird
			return $this->msg( 'changeauthor-weirderror' )->text();
		}

		$retval = Xml::openElement( 'form', [ 'method' => 'post', 'action' => $wgScript ] );
		$retval .= Html::hidden( 'title', $this->getPageTitle()->getPrefixedDBkey() );
		$retval .= Html::hidden( 'action', 'change' );
		$retval .= Html::hidden( 'targetpage', $title->getPrefixedDBkey() );
		$retval .= Xml::openElement( 'fieldset' );
		$retval .= Xml::element( 'p', [], $this->msg( 'changeauthor-explanation-multi' )->text() );
		$retval .= Xml::inputLabel( $this->msg( 'changeauthor-comment' )->text(), 'comment', 'comment', 50 );
		$retval .= Xml::submitButton(
			$this->msg( 'changeauthor-changeauthors-multi',
				count( $revs )
			)->parse()
		);
		if ( $errMsg != '' ) {
			$retval .= Xml::openElement( 'p' ) . Xml::openElement( 'b' );
			$retval .= Xml::element( 'font', [ 'color' => 'red' ], $errMsg );
			$retval .= Xml::closeElement( 'b' ) . Xml::closeElement( 'p' );
		}
		$retval .= Xml::element( 'h2', [], $title->getPrefixedText() );
		$retval .= Xml::openElement( 'ul' );
		$count = count( $revs );
		foreach ( $revs as $i => $rev ) {
			$retval .= $this->buildRevisionLine( $rev, $title, ( $i == 0 ), ( $i == $count - 1 ) );
		}
		$retval .= Xml::closeElement( 'ul' );
		$retval .= Xml::closeElement( 'fieldset' );
		$retval .= Xml::closeElement( 'form' );
		return $retval;
	}

	/**
	 * Builds a form that allows changing one revision's author
	 *
	 * @param Revision $rev Revision object
	 * @param string $errMsg Error message
	 * @return string HTML
	 */
	private function buildOneRevForm( $rev, $errMsg = '' ) {
		global $wgScript;
		$retval = Xml::openElement( 'form', [ 'method' => 'post', 'action' => $wgScript ] );
		$retval .= Html::hidden( 'title', $this->getPageTitle()->getPrefixedDBkey() );
		$retval .= Html::hidden( 'action', 'change' );
		$retval .= Html::hidden( 'targetrev', $rev->getId() );
		$retval .= Xml::openElement( 'fieldset' );
		$retval .= Xml::element( 'p', [], $this->msg( 'changeauthor-explanation-single' )->text() );
		$retval .= Xml::inputLabel( $this->msg( 'changeauthor-comment' )->text(), 'comment', 'comment' );
		$retval .= Xml::submitButton( $this->msg( 'changeauthor-changeauthors-single' )->text() );
		if ( $errMsg != '' ) {
			$retval .= Xml::openElement( 'p' ) . Xml::openElement( 'b' );
			$retval .= Xml::element( 'font', [ 'color' => 'red' ], $errMsg );
			$retval .= Xml::closeElement( 'b' ) . Xml::closeElement( 'p' );
		}
		$retval .= Xml::element( 'h2', [], $this->msg( 'changeauthor-revview', $rev->getId(), $rev->getTitle()->getPrefixedText() )->text() );
		$retval .= Xml::openElement( 'ul' );
		$retval .= $this->buildRevisionLine( $rev, $rev->getTitle() );
		$retval .= Xml::closeElement( 'ul' );
		$retval .= Xml::closeElement( 'fieldset' );
		$retval .= Xml::closeElement( 'form' );
		return $retval;
	}

	/**
	 * Extracts an array needed by changeRevAuthors() from the context-sensitive
	 * WebRequest object
	 * @return array
	 */
	private function parseChangeRequest() {
		$request = $this->getRequest();
		$vals = $request->getValues();
		$retval = [];
		foreach ( $vals as $name => $val ) {
			if ( substr( $name, 0, 9 ) != 'user-new-' ) {
				continue;
			}
			$revid = substr( $name, 9 );
			if ( !is_numeric( $revid ) ) {
				continue;
			}

			$new = User::newFromName( $val, false );
			if ( !$new ) { // Can this even happen?
				return $this->msg( 'changeauthor-invalid-username', $val )->text();
			}
			if ( $new->getId() == 0 && $val != 'MediaWiki default' && !User::isIP( $new->getName() ) ) {
				return $this->msg( 'changeauthor-nosuchuser', $val )->text();
			}
			$old = User::newFromName( $request->getVal( "user-old-$revid" ), false );
			if ( !$old->getName() ) {
				return $this->msg( 'changeauthor-invalidform' )->text();
			}
			if ( $old->getName() != $new->getName() ) {
				$retval[$revid] = [ $old, $new ];
			}
		}
		return $retval;
	}

	/**
	 * Changes revision authors in the database
	 *
	 * @param array $authors key=revid value=array(User from, User to)
	 * @param mixed $comment Log comment
	 */
	private function changeRevAuthors( $authors, $comment ) {
		$dbw = wfGetDB( DB_PRIMARY );
		$dbw->startAtomic( __METHOD__ );
		$editcounts = []; // Array to keep track of EC mutations; key=userid, value=mutation

		foreach ( $authors as $id => $users ) {
			$dbw->update(
				'revision',
				/* SET */[
					'rev_user' => $users[1]->getId(),
					'rev_user_text' => $users[1]->getName()
				],
				[ 'rev_id' => $id ], // WHERE
				__METHOD__
			);
			$rev = Revision::newFromId( $id );

			$logEntry = new ManualLogEntry( 'changeauth', 'changeauth' );
			$logEntry->setPerformer( $this->getUser() );
			$logEntry->setTarget( $rev->getTitle() );
			$logEntry->setComment( $comment );
			$logEntry->setParameters( [
				'4::revid' => $id,
				'5::originalauthor' => $users[0]->getName(),
				'6::newauthor' => $users[1]->getName(),
			] );
			$logId = $logEntry->insert();
			$logEntry->publish( $logId );

			AtEase::suppressWarnings();
			$editcounts[$users[1]->getId()]++;
			$editcounts[$users[0]->getId()]--;
			AtEase::restoreWarnings();
		}

		foreach ( $editcounts as $userId => $mutation ) {
			if ( $mutation == 0 || $userId == 0 ) {
				continue;
			}
			if ( $mutation > 0 ) {
				$mutation = "+$mutation";
			}
			$dbw->update(
				'user',
				[ "user_editcount=user_editcount$mutation" ],
				[ 'user_id' => $userId ],
				__METHOD__
			);
			if ( $dbw->affectedRows() == 0 ) {
				// Let's have mercy on those who don't have a proper DB server
				// (but not enough to spare their primary database)
				$count = $dbw->selectField(
					'revision',
					'COUNT(rev_user)',
					[ 'rev_user' => $userId ],
					__METHOD__
				);
				$dbw->update(
					'user',
					[ 'user_editcount' => $count ],
					[ 'user_id' => $userId ],
					__METHOD__
				);
			}
		}

		$dbw->endAtomic( __METHOD__ );
	}
}
