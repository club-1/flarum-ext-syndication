<?php

/*
 * Copyright or © or Copr. flarum-ext-syndication contributor : Amaury
 * Carrade (2016)
 *
 * https://amaury.carrade.eu
 *
 * This software is a computer program whose purpose is to provides RSS
 * and Atom feeds to Flarum.
 *
 * This software is governed by the CeCILL-B license under French law and
 * abiding by the rules of distribution of free software.  You can  use,
 * modify and/ or redistribute the software under the terms of the CeCILL-B
 * license as circulated by CEA, CNRS and INRIA at the following URL
 * "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and  rights to copy,
 * modify and redistribute granted by the license, users are provided only
 * with a limited warranty  and the software's author,  the holder of the
 * economic rights,  and the successive licensors  have only  limited
 * liability.
 *
 * In this respect, the user's attention is drawn to the risks associated
 * with loading,  using,  modifying and/or developing or reproducing the
 * software by the user in light of its specific status of free software,
 * that may mean  that it is complicated to manipulate,  and  that  also
 * therefore means  that it is reserved for developers  and  experienced
 * professionals having in-depth computer knowledge. Users are therefore
 * encouraged to load and test the software's suitability as regards their
 * requirements in conditions enabling the security of their systems and/or
 * data to be ensured and,  more generally, to use and operate it in the
 * same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL-B license and that you accept its terms.
 *
 */

namespace IanM\FlarumFeeds\Controller;

use Flarum\Api\Client as ApiClient;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface as Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Displays feeds for topics, either last updated or created, possibly filtered by tag.
 * This is the main controller for feeds listing discussions; other extends this one with
 * specific parameters.
 */
class DiscussionsActivityFeedController extends AbstractFeedController
{
    /**
     * @var bool true to display topics ordered by creation date with first post instead of activity
     */
    private $lastTopics;

    /**
     * @param Factory             $view
     * @param ApiClient           $api
     * @param TranslatorInterface $translator
     * @param bool                $lastTopics
     */
    public function __construct(Factory $view, ApiClient $api, TranslatorInterface $translator, SettingsRepositoryInterface $settings, UrlGenerator $url, $lastTopics = false)
    {
        parent::__construct($view, $api, $translator, $settings, $url);

        $this->lastTopics = $lastTopics;
    }

    /**
     * @param Request $request
     *
     * @return array
     */
    protected function getFeedContent(Request $request)
    {
        $queryParams = $request->getQueryParams();

        $sortMap = resolve('flarum.forum.discussions.sortmap');

        $sort = Arr::pull($queryParams, 'sort');
        $q = Arr::pull($queryParams, 'q');
        $tags = $this->getTags($request);

        if ($tags != null) {
            $tags_search = [];
            foreach ($tags as $tag) {
                $tags_search[] = 'tag:'.$tag;
            }

            $q .= (!empty($q) ? ' ' : '').implode(' ', $tags_search);
        }

        $params = [
            'sort'    => $sort && isset($sortMap[$sort]) ? $sortMap[$sort] : ($this->lastTopics ? $sortMap['newest'] : $sortMap['latest']),
            'filter'  => compact('q'),
            'page'    => ['offset' => 0, 'limit' => $this->getSetting('entries-count')],
            'include' => $this->lastTopics ? 'firstPost,user' : 'lastPost,lastPostedUser',
        ];

        $actor = $this->getActor($request);
        $forum = $this->getForumDocument($request, $actor);
        $last_discussions = $this->getDocument($request, $actor, $params);

        $entries = [];
        $lastModified = null;

        foreach ((array) $last_discussions->data as $discussion) {
            if ($discussion->type != 'discussions') {
                continue;
            }

            if ($this->lastTopics && isset($discussion->relationships->firstPost)) {
                $content = $this->getRelationship($last_discussions, $discussion->relationships->firstPost);
            } elseif (isset($discussion->relationships->lastPost)) {
                $content = $this->getRelationship($last_discussions, $discussion->relationships->lastPost);
            } else {  // Happens when the first or last post is soft-deleted
                $content = new \stdClass();
                $content->contentHtml = '';
            }

            if ($this->lastTopics) {
                $author = isset($discussion->relationships->user) ? $this->getRelationship($last_discussions, $discussion->relationships->user)->username : '[deleted]';
            } else {
                $author = isset($discussion->relationships->lastPostedUser) ? $this->getRelationship($last_discussions, $discussion->relationships->lastPostedUser)->username : '[deleted]';
            }
            $entries[] = [
                'title'       => $discussion->attributes->title,
                'content'     => $this->summarize($this->stripHTML($content->contentHtml)),
                'id'          => $this->url->to('forum')->route('discussion', ['id' => $discussion->id.'-'.$discussion->attributes->slug]),
                'permalink'   => $this->url->to('forum')->route('discussion', ['id' => $discussion->attributes->slug, 'near' => $content->number]),
                'pubdate'     => $this->parseDate($this->lastTopics ? $discussion->attributes->createdAt : $discussion->attributes->lastPostedAt),
                'author'      => $author,
            ];

            $modified = $this->parseDate($this->lastTopics ? $discussion->attributes->createdAt : $discussion->attributes->lastPostedAt);

            if ($lastModified === null || $lastModified < $modified) {
                $lastModified = $modified;
            }
        }

        // TODO real tag names
        if ($this->lastTopics) {
            if (empty($tags)) {
                $title = $this->translator->trans('ianm-syndication.forum.feeds.titles.main_d_title', ['{forum_name}' => $forum->attributes->title, '{forum_desc}' => $forum->attributes->description]);
                $description = $this->translator->trans('ianm-syndication.forum.feeds.titles.main_d_subtitle', ['{forum_name}' => $forum->attributes->title, '{forum_desc}' => $forum->attributes->description]);
            } else {
                $title = $this->translator->trans('ianm-syndication.forum.feeds.titles.tag_d_title', ['{forum_name}' => $forum->attributes->title, '{forum_desc}' => $forum->attributes->description, '{tag}' => implode(', ', $tags)]);
                $description = $this->translator->trans('ianm-syndication.forum.feeds.titles.tag_d_subtitle', ['{forum_name}' => $forum->attributes->title, '{forum_desc}' => $forum->attributes->description, '{tag}' => implode(', ', $tags)]);
            }
        } else {
            if (empty($tags)) {
                $title = $this->translator->trans('ianm-syndication.forum.feeds.titles.main_title', ['{forum_name}' => $forum->attributes->title, '{forum_desc}' => $forum->attributes->description]);
                $description = $this->translator->trans('ianm-syndication.forum.feeds.titles.main_subtitle', ['{forum_name}' => $forum->attributes->title, '{forum_desc}' => $forum->attributes->description]);
            } else {
                $title = $this->translator->trans('ianm-syndication.forum.feeds.titles.tag_title', ['{forum_name}' => $forum->attributes->title, '{forum_desc}' => $forum->attributes->description, '{tag}' => implode(', ', $tags)]);
                $description = $this->translator->trans('ianm-syndication.forum.feeds.titles.tag_subtitle', ['{forum_name}' => $forum->attributes->title, '{forum_desc}' => $forum->attributes->description, '{tag}' => implode(', ', $tags)]);
            }
        }

        return [
            'forum'        => $forum,
            'title'        => $title,
            'description'  => $description,
            'link'         => $forum->attributes->baseUrl,
            'pubDate'      => new \DateTime(),
            'lastModified' => $lastModified,
            'entries'      => $entries,
        ];
    }

    /**
     * Get the result of an API request to list discussions.
     *
     * @param Request $request
     * @param User    $actor
     * @param array   $params
     *
     * @return object
     */
    private function getDocument(Request $request, User $actor, array $params)
    {
        return $this->getAPIDocument($request, '/discussions', $actor, $params);
    }

    /**
     * Returns the tags to filter on.
     *
     * @param Request $request
     *
     * @return array|null Tags or null
     */
    protected function getTags(Request $request)
    {
        return null;
    }
}
