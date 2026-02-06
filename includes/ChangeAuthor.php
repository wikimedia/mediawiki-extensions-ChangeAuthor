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

use MediaWiki\CommentStore\CommentStore;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Html\Html;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserRigorOptions;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\SelectQueryBuilder;

class ChangeAuthor extends SpecialPage {

	private const CONSTRUCTOR_OPTIONS = [
		MainConfigNames::Script
	];

	/** @var RevisionLookup */
	private $revisionLookup;

	/** @var Config */
	private $config;

	/** @var UserFactory */
	private $userFactory;

	/** @var CommentStore */
	private $commentStore;

	/** @var ActorNormalization */
	private $actorNormalization;

	/** @var ILoadBalancer */
	private $loadBalancer;

	/**
	 * @param RevisionLookup $revisionLookup
	 * @param Config $config
	 * @param UserFactory $userFactory
	 * @param CommentStore $commentStore
	 * @param ActorNormalization $actorNormalization
	 * @param ILoadBalancer $loadBalancer
	 */
	public function __construct(
		RevisionLookup $revisionLookup,
		Config $config,
		UserFactory $userFactory,
		CommentStore $commentStore,
		ActorNormalization $actorNormalization,
		ILoadBalancer $loadBalancer
	) {
		$options = new ServiceOptions( self::CONSTRUCTOR_OPTIONS, $config );
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );

		$this->revisionLookup = $revisionLookup;
		$this->config = $config;
		$this->userFactory = $userFactory;
		$this->commentStore = $commentStore;
		$this->actorNormalization = $actorNormalization;
		$this->loadBalancer = $loadBalancer;

		parent::__construct(
			'ChangeAuthor', /* class */
			'changeauthor'/* restriction */
		);
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

		$out->setPageTitle( $this->msg( 'changeauthor-title' )->escaped() );

		if ( $par !== null ) {
			$obj = $this->parseTitleOrRevID( $par );
			if ( $obj instanceof Title ) {
				if ( $obj->exists() ) {
					$out->addHTML( $this->buildRevisionList( $obj ) );
				} else {
					$out->addHTML(
						$this->buildInitialForm(
							$this->msg( 'changeauthor-nosuchtitle', $obj->getPrefixedText() )->text()
						)
					);
				}
				return;
			} elseif ( $obj instanceof RevisionRecord ) {
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
					$revision = $this->revisionLookup->getRevisionById( $targetRev );
					$out->addHTML( $this->buildOneRevForm( $revision, $arr ) );
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
					$out->addHTML(
						$this->buildInitialForm(
							$this->msg( 'changeauthor-nosuchtitle', $obj->getPrefixedText() )->text()
						)
					);
				}
			} elseif ( $obj instanceof RevisionRecord ) {
				$out->addHTML( $this->buildOneRevForm( $obj ) );
			}
			return;
		}
		$out->addHTML( $this->buildInitialForm() );
	}

	/**
	 * Parse what can be a revision ID or an article name
	 * @param mixed $str Revision ID or an article name
	 * @return Title|RevisionRecord|null
	 */
	private function parseTitleOrRevID( $str ) {
		$result = false;
		if ( is_numeric( $str ) ) {
			$result = $this->revisionLookup->getRevisionById( $str );
		}
		if ( !$result ) {
			$result = Title::newFromURL( $str );
		}
		return $result;
	}

	/**
	 * Builds the form that asks for a page name or revid
	 *
	 * @param string $errMsg Error message
	 * @return string HTML
	 */
	private function buildInitialForm( $errMsg = '' ) {
		global $wgScript;
		$retval = Html::openElement( 'form', [ 'method' => 'post', 'action' => $wgScript ] );
		$retval .= Html::hidden( 'title', $this->getPageTitle()->getPrefixedDBkey() );
		$retval .= Html::hidden( 'action', 'list' );
		$retval .= Html::openElement( 'fieldset' );
		$retval .= Html::element( 'legend', [], $this->msg( 'changeauthor-search-box' )->text() );
		$retval .= Html::label( $this->msg( 'changeauthor-pagename-or-revid' )->text(), 'pagename-revid' ) .
			"\u{00A0}" . Html::input( 'pagename-revid', '', 'text', [ 'id' => 'pagename-revid' ] );
		$retval .= Html::submitButton( $this->msg( 'changeauthor-pagenameform-go' )->text(), [] );
		if ( $errMsg != '' ) {
			$retval .= Html::openElement( 'p' ) . Html::openElement( 'b' );
			$retval .= Html::element( 'font', [ 'color' => 'red' ], $errMsg );
			$retval .= Html::closeElement( 'b' ) . Html::closeElement( 'p' );
		}
		$retval .= Html::closeElement( 'fieldset' );
		$retval .= Html::closeElement( 'form' );
		return $retval;
	}

	/**
	 * Builds a line for revision $rev
	 * Helper to buildRevisionList() and buildOneRevForm()
	 *
	 * @param RevisionRecord $rev Revision object
	 * @param PageIdentity $pageIdentity
	 * @param bool $isFirst Set to true if $rev is the first revision
	 * @param bool $isLast Set to true if $rev is the last revision
	 * @return string HTML
	 */
	private function buildRevisionLine( $rev, $pageIdentity, $isFirst = false, $isLast = false ) {
		$linkRenderer = $this->getLinkRenderer();
		// Build curlink
		if ( $isFirst ) {
			$curLink = $this->msg( 'cur' )->text();
		} else {
			$curLink = $linkRenderer->makeKnownLink(
				$pageIdentity,
				$this->msg( 'cur' )->text(),
				[],
				[ 'oldid' => $rev->getId(), 'diff' => 'cur' ]
			);
		}

		if ( $isLast ) {
			$lastLink = $this->msg( 'last' )->text();
		} else {
			$lastLink = $linkRenderer->makeKnownLink(
				$pageIdentity,
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
			$link = $linkRenderer->makeKnownLink( $pageIdentity, $date, [], [ 'oldid' => $rev->getId() ] );
		} else {
			$link = $date;
		}

		// Build user textbox
		$userName = $rev->getUser()->getName();
		$userBox = Html::input(
			"user-new-{$rev->getId()}",
			$this->getRequest()->getVal( "user-{$rev->getId()}", $rev->getUser()->getName() ),
			'text',
			[ 'size' => 50 ]
		);
		$userText = Html::hidden( "user-old-{$rev->getId()}", $userName ) . $userName;

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
			->formatBlock( $rev->getComment()->text, Title::castFromPageIdentity( $pageIdentity ) );

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
		$script = $this->config->get( MainConfigNames::Script );

		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$res = $dbr->newSelectQueryBuilder()
			->select( '*' )
			->from( 'revision' )
			->where( [ 'rev_page' => $title->getArticleID() ] )
			->orderBy( 'rev_timestamp', SelectQueryBuilder::SORT_DESC )
			->limit( 50 )
			->caller( __METHOD__ )
			->fetchResultSet();

		$revs = [];
		foreach ( $res as $r ) {
			$revs[] = $this->revisionLookup->getRevisionById( $r->rev_id );
		}

		if ( $revs === [] ) {
			// That's *very* weird
			return $this->msg( 'changeauthor-weirderror' )->text();
		}

		$retval = Html::openElement( 'form', [ 'method' => 'post', 'action' => $script ] );
		$retval .= Html::hidden( 'title', $this->getPageTitle()->getPrefixedDBkey() );
		$retval .= Html::hidden( 'action', 'change' );
		$retval .= Html::hidden( 'targetpage', $title->getPrefixedDBkey() );
		$retval .= Html::openElement( 'fieldset' );
		$retval .= Html::element( 'p', [], $this->msg( 'changeauthor-explanation-multi' )->text() );
		$retval .= Html::label( $this->msg( 'changeauthor-comment' )->text(), 'comment' ) .
			"\u{00A0}" . Html::input( 'comment', '', 'text', [ 'id' => 'comment', 'size' => 50 ] );
		$retval .= Html::submitButton(
			$this->msg( 'changeauthor-changeauthors-multi',
				count( $revs )
			)->parse(),
			[]
		);
		if ( $errMsg != '' ) {
			$retval .= Html::openElement( 'p' ) . Html::openElement( 'b' );
			$retval .= Html::element( 'font', [ 'color' => 'red' ], $errMsg );
			$retval .= Html::closeElement( 'b' ) . Html::closeElement( 'p' );
		}
		$retval .= Html::element( 'h2', [], $title->getPrefixedText() );
		$retval .= Html::openElement( 'ul' );
		$count = count( $revs );
		foreach ( $revs as $i => $rev ) {
			$retval .= $this->buildRevisionLine( $rev, $title, ( $i == 0 ), ( $i == $count - 1 ) );
		}
		$retval .= Html::closeElement( 'ul' );
		$retval .= Html::closeElement( 'fieldset' );
		$retval .= Html::closeElement( 'form' );
		return $retval;
	}

	/**
	 * Builds a form that allows changing one revision's author
	 *
	 * @param RevisionRecord $rev Revision object
	 * @param string $errMsg Error message
	 * @return string HTML
	 */
	private function buildOneRevForm( $rev, $errMsg = '' ) {
		$script = $this->config->get( MainConfigNames::Script );

		$retval = Html::openElement( 'form', [ 'method' => 'post', 'action' => $script ] );
		$retval .= Html::hidden( 'title', $this->getPageTitle()->getPrefixedDBkey() );
		$retval .= Html::hidden( 'action', 'change' );
		$retval .= Html::hidden( 'targetrev', $rev->getId() );
		$retval .= Html::openElement( 'fieldset' );
		$retval .= Html::element( 'p', [], $this->msg( 'changeauthor-explanation-single' )->text() );
		$retval .= Html::label( $this->msg( 'changeauthor-comment' )->text(), 'comment' )
			. "\u{00A0}" . Html::input( 'comment', '', 'text', [ 'id' => 'comment' ] );
		$retval .= Html::submitButton( $this->msg( 'changeauthor-changeauthors-single' )->text(), [] );
		if ( $errMsg != '' ) {
			$retval .= Html::openElement( 'p' ) . Html::openElement( 'b' );
			$retval .= Html::element( 'font', [ 'color' => 'red' ], $errMsg );
			$retval .= Html::closeElement( 'b' ) . Html::closeElement( 'p' );
		}
		$retval .= Html::element(
			'h2',
			[],
			$this->msg( 'changeauthor-revview', $rev->getId(), $rev->getPage()->getPrefixedText() )->text()
		);
		$retval .= Html::openElement( 'ul' );
		$retval .= $this->buildRevisionLine( $rev, $rev->getPage() );
		$retval .= Html::closeElement( 'ul' );
		$retval .= Html::closeElement( 'fieldset' );
		$retval .= Html::closeElement( 'form' );
		return $retval;
	}

	/**
	 * Extracts an array needed by changeRevAuthors() from the context-sensitive
	 * WebRequest object
	 *
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

			$new = $this->userFactory->newFromName( $val, UserRigorOptions::RIGOR_NONE );
			if ( !$new ) { // Can this even happen?
				return $this->msg( 'changeauthor-invalid-username', $val )->text();
			}
			if ( $new->getId() == 0 && $val != 'MediaWiki default' && !IPUtils::isIPAddress( $new->getName() ) ) {
				return $this->msg( 'changeauthor-nosuchuser', $val )->text();
			}
			$old = $this->userFactory->newFromName(
				$request->getVal( "user-old-$revid" ),
				UserRigorOptions::RIGOR_NONE
			);
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
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
		$dbw->startAtomic( __METHOD__ );
		$editcounts = []; // Array to keep track of EC mutations; key=userid, value=mutation
		foreach ( $authors as $id => $users ) {
			/** @var User[] $users */
			$dbw->update(
				'revision',
				/* SET */[
					'rev_actor' => $this->actorNormalization->acquireActorId( $users[1], $dbw ),
				],
				[ 'rev_id' => $id ], // WHERE
				__METHOD__
			);
			$rev = $this->revisionLookup->getRevisionById( $id );

			$logEntry = new ManualLogEntry( 'changeauth', 'changeauth' );
			$logEntry->setPerformer( $this->getUser() );
			$logEntry->setTarget( $rev->getPageAsLinkTarget() );
			$logEntry->setComment( $comment );
			$logEntry->setParameters( [
				'4::revid' => $id,
				'5::originalauthor' => $users[0]->getName(),
				'6::newauthor' => $users[1]->getName(),
			] );
			$logId = $logEntry->insert();
			$logEntry->publish( $logId );

			$editcounts[$users[1]->getId()] ??= 0;
			$editcounts[$users[0]->getId()] ??= 0;
			$editcounts[$users[1]->getId()]++;
			$editcounts[$users[0]->getId()]--;
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
