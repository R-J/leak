<?php

$PluginInfo['leak'] = array(
    'Name' => 'Leak Discussion',
    'Description' => 'Allows leaking a single discussion to a user whithout category permissions.',
    'Version' => '0.1',
    'RequiredApplications' => array('Vanilla' => '2.2'),
    'MobileFriendly' => true,
    'HasLocale' => false,
    'SettingsUrl' => '/dashboard/settings/leak',
    'SettingsPermission' => 'Garden.Settings.Manage',
    'RegisterPermissions' => array('Leak.Allow'),
    'Author' => 'Robin Jurinka',
    'AuthorUrl' => 'https://vanillaforums.org/profile/R_J',
    'License' => 'MIT'
);


/**
 * Only show option to leak a discussion from discussion options for role with leak.Allow or discussion
 */
class LeakPlugin extends Gdn_Plugin {
    public function setup() {
        touchConfig('leak.AllowDiscussionAuthor', false);
    }

    public function settingsController_leak_create($sender, $args) {
        $sender->addSideMenu('dashboard/settings/plugins');
        $sender->setData('Title', t('Leak Discussion Settings'));
        $sender->setData('Description', t('This plugin allows discussion authors and members of special roles (see permission "Leak.Allow" to leak a discussion to a user who has no view permission for the discussions category.'));

        $configurationModule = new configurationModule($sender);
        $configurationModule->initialize(
            array(
                'leak.AllowDiscussionAuthor' => array(
                    'Control' => 'CheckBox',
                    'Description' => 'You can choose if a discussion author, independent of his role, has the possibility to leak a discussion.',
                    'LabelCode' => 'Allow leaking by author',
                    'Default' => false
                )
            )
        );
        $configurationModule->renderAll();
    }

    public function base_afterDiscussionFilters_handler($sender) {
        if (Gdn::userMetaModel()->getUserMeta($userID, 'LeakedDiscussions', array())['LeakedDiscussions'] == array()) {
            return;
        }
        $cssClass = 'LeakDiscussions';
        if (
            Gdn::controller()->ControllerName == 'discussionscontroller' &&
            Gdn::controller()->RequestMethod == 'leaked'
        ) {
            $cssClass .= ' Active';
        }
        echo '<li class="'.$cssClass.'">',
            anchor(
                sprite('SpLeaked').t('Leaked'),
                '/discussions/leaked'
            ),
            '</li>';
    }

    /**
     * Copy of Vanillas discussionsController->index method.
     * 
     * Only very few tweaks to make it work without category permissions. 
     */
    public function discussionsController_leaked_create($sender, $args) {
        // Only show to users with entries for "LeakedDiscussions" in UserMeta.
        $userID = Gdn::session()->UserID;
        $leakedDiscussions = Gdn::userMetaModel()->getUserMeta($userID, 'LeakedDiscussions', array())['LeakedDiscussions'];
        if ($leakedDiscussions == array()) {
            return;
        }

        // Figure out which discussions layout to choose (Defined on "Homepage" settings page).
        $Layout = c('Vanilla.Discussions.Layout');
        switch ($Layout) {
            case 'table':
                if ($sender->SyndicationMethod == SYNDICATION_NONE) {
                    $sender->View = 'table';
                }
                break;
            default:
                $sender->View = 'index';
                break;
        }
        Gdn_Theme::section('DiscussionList');

        // Set canonical URL
        $sender->canonicalUrl(url(ConcatSep('/', 'discussions', 'leaked'), true));

        // Setup head.
        $sender->setData('Title', t('Leaked Discussions'));
        $sender->setData('Description', c('Garden.Description', null));
        if ($sender->Head) {
            $sender->Head->AddRss(url('/discussions/leaked/feed.rss', true), $sender->Head->title());
        }

        // Add modules
        $sender->addModule('DiscussionFilterModule');
        $sender->addModule('NewDiscussionModule');
        $sender->addModule('CategoriesModule');
        $sender->addModule('BookmarkedModule');
        $sender->setData('Breadcrumbs', array(array('Name' => t('Leaked Discussions'), 'Url' => '/discussions/leaked')));


        // Set criteria & get discussions data
        $DiscussionModel = new DiscussionModel();

        // Disable category based permission check.
        saveToConfig('Garden.Permissions.Disabled.Category', true, array('Save' => false));
        // Only fetch leaked discussions for session user.
        Gdn::sql()->whereIn('d.DiscussionID', $leakedDiscussions);
        $discussions = $DiscussionModel->get($Page, $Limit);
        // Reenable category permission checks.
        saveToConfig('Garden.Permissions.Disabled.Category', false, array('Save' => false));

        // Fugly. Should be cached at least or made a config setting. 
        $discussionUrlPrefix = url('discussion', true);
        $discussionUrlPrefixLength = strlen($discussionUrlPrefix);
        foreach($discussions as $discussion) {
            $discussion->Url = url('discussion', true).'/leaked'.substr($discussion->Url, $discussionUrlPrefixLength);
        }

        $sender->DiscussionData = $discussions;
        
        $sender->setData('Discussions', $sender->DiscussionData);
        $sender->setData('CountDiscussions', count($sender->DiscussionData));
        $sender->Category = false;

        $sender->setJson('Loading');

        $sender->render();
    }


    /**
     * Copy of Vanillas discussionController->index method
     *    
     * Only very few tweaks to make it work without category permissions. 
     */
    public  function discussionController_leaked_create($sender, $DiscussionID = '', $DiscussionStub = '', $Page = '') {
        // Setup head
        $Session = Gdn::session();
        $sender->addJsFile('jquery.autosize.min.js');
        $sender->addJsFile('autosave.js');
        $sender->addJsFile('discussion.js');
        Gdn_Theme::section('Discussion');

        // Load the discussion record
        $DiscussionID = (is_numeric($DiscussionID) && $DiscussionID > 0) ? $DiscussionID : 0;

        $userID = Gdn::session()->UserID;
        $leakedDiscussions = Gdn::userMetaModel()->getUserMeta($userID, 'LeakedDiscussions', array())['LeakedDiscussions'];

        saveToConfig('Garden.Permissions.Disabled.Category', true, array('Save' => false));
        if (!array_key_exists('Discussion', $sender->Data)) {
            $sender->setData('Discussion', $sender->DiscussionModel->getID($DiscussionID), true);
        }
        saveToConfig('Garden.Permissions.Disabled.Category', false, array('Save' => false));

        if (!is_object($sender->Discussion)) {
            throw notFoundException('Discussion');
        }

        // Define the query offset & limit.
        $Limit = c('Vanilla.Comments.PerPage', 30);

        $OffsetProvided = $Page != '';
        list($Offset, $Limit) = offsetLimit($Page, $Limit);

        // Check permissions
        // $sender->permission('Vanilla.Discussions.View', true, 'Category', $sender->Discussion->PermissionCategoryID);
        if (!in_array($DiscussionID, $leakedDiscussions)) {
            throw NotFoundException('Discussion');
        }
        $sender->setData('CategoryID', $sender->CategoryID = $sender->Discussion->CategoryID, true);

        if (strcasecmp(val('Type', $sender->Discussion), 'redirect') === 0) {
            $sender->redirectDiscussion($sender->Discussion);
        }

        $Category = CategoryModel::categories($sender->Discussion->CategoryID);
        $sender->setData('Category', $Category);

        if ($CategoryCssClass = val('CssClass', $Category)) {
            Gdn_Theme::section($CategoryCssClass);
        }

        $sender->setData('Breadcrumbs', CategoryModel::getAncestors($sender->CategoryID));

        // Setup
        $sender->title($sender->Discussion->Name);

        // Actual number of comments, excluding the discussion itself.
        $ActualResponses = $sender->Discussion->CountComments;

        $sender->Offset = $Offset;
        if (c('Vanilla.Comments.AutoOffset')) {
//         if ($sender->Discussion->CountCommentWatch > 1 && $OffsetProvided == '')
//            $sender->addDefinition('ScrollTo', 'a[name=Item_'.$sender->Discussion->CountCommentWatch.']');
            if (!is_numeric($sender->Offset) || $sender->Offset < 0 || !$OffsetProvided) {
                // Round down to the appropriate offset based on the user's read comments & comments per page
                $CountCommentWatch = $sender->Discussion->CountCommentWatch > 0 ? $sender->Discussion->CountCommentWatch : 0;
                if ($CountCommentWatch > $ActualResponses) {
                    $CountCommentWatch = $ActualResponses;
                }

                // (((67 comments / 10 perpage) = 6.7) rounded down = 6) * 10 perpage = offset 60;
                $sender->Offset = floor($CountCommentWatch / $Limit) * $Limit;
            }
            if ($ActualResponses <= $Limit) {
                $sender->Offset = 0;
            }

            if ($sender->Offset == $ActualResponses) {
                $sender->Offset -= $Limit;
            }
        } else {
            if ($sender->Offset == '') {
                $sender->Offset = 0;
            }
        }

        if ($sender->Offset < 0) {
            $sender->Offset = 0;
        }


        $LatestItem = $sender->Discussion->CountCommentWatch;
        if ($LatestItem === null) {
            $LatestItem = 0;
        } elseif ($LatestItem < $sender->Discussion->CountComments) {
            $LatestItem += 1;
        }

        $sender->setData('_LatestItem', $LatestItem);

        // Set the canonical url to have the proper page title.
        $sender->canonicalUrl(discussionUrl($sender->Discussion, pageNumber($sender->Offset, $Limit, 0, false)));

//      url(ConcatSep('/', 'discussion/'.$sender->Discussion->DiscussionID.'/'. Gdn_Format::url($sender->Discussion->Name), PageNumber($sender->Offset, $Limit, TRUE, Gdn::session()->UserID != 0)), true), Gdn::session()->UserID == 0);

        // Load the comments
        $sender->setData('Comments', $sender->CommentModel->get($DiscussionID, $Limit, $sender->Offset));

        $PageNumber = PageNumber($sender->Offset, $Limit);
        $sender->setData('Page', $PageNumber);
        if ($sender->Head) {
            $sender->Head->addTag('meta', array('property' => 'og:type', 'content' => 'article'));
        }
        
        include_once(PATH_LIBRARY.'/vendors/simplehtmldom/simple_html_dom.php');
        if ($PageNumber == 1) {
            $sender->description(sliceParagraph(Gdn_Format::plainText($sender->Discussion->Body, $sender->Discussion->Format), 160));
            // Add images to head for open graph
            $Dom = str_get_html(Gdn_Format::to($sender->Discussion->Body, $sender->Discussion->Format));
        } else {
            $sender->Data['Title'] .= sprintf(t(' - Page %s'), PageNumber($sender->Offset, $Limit));

            $FirstComment = $sender->data('Comments')->firstRow();
            $FirstBody = val('Body', $FirstComment);
            $FirstFormat = val('Format', $FirstComment);
            $sender->description(sliceParagraph(Gdn_Format::plainText($FirstBody, $FirstFormat), 160));
            // Add images to head for open graph
            $Dom = str_get_html(Gdn_Format::to($FirstBody, $FirstFormat));
        }

        if ($Dom) {
            foreach ($Dom->find('img') as $img) {
                if (isset($img->src)) {
                    $sender->image($img->src);
                }
            }
        }

        // Queue notification.
        if ($sender->Request->get('new') && c('Vanilla.QueueNotifications')) {
            $sender->addDefinition('NotifyNewDiscussion', 1);
        }

        // Make sure to set the user's discussion watch records
        $sender->CommentModel->SetWatch($sender->Discussion, $Limit, $sender->Offset, $sender->Discussion->CountComments);

        // Build a pager
        $PagerFactory = new Gdn_PagerFactory();
        $sender->EventArguments['PagerType'] = 'Pager';
        $sender->fireEvent('BeforeBuildPager');
        $sender->Pager = $PagerFactory->getPager($sender->EventArguments['PagerType'], $sender);
        $sender->Pager->ClientID = 'Pager';

        $sender->Pager->configure(
            $sender->Offset,
            $Limit,
            $ActualResponses,
            array('DiscussionUrl')
        );
        $sender->Pager->Record = $sender->Discussion;
        PagerModule::current($sender->Pager);
        $sender->fireEvent('AfterBuildPager');

        // Define the form for the comment input
        $sender->Form = Gdn::Factory('Form', 'Comment');
        $sender->Form->Action = url('/post/comment/');
        $sender->DiscussionID = $sender->Discussion->DiscussionID;
        $sender->Form->addHidden('DiscussionID', $sender->DiscussionID);
        $sender->Form->addHidden('CommentID', '');

        // Look in the session stash for a comment
        $StashComment = $Session->getPublicStash('CommentForDiscussionID_'.$sender->Discussion->DiscussionID);
        if ($StashComment) {
            $sender->Form->setFormValue('Body', $StashComment);
        }

        // Retrieve & apply the draft if there is one:
        if (Gdn::session()->UserID) {
            $DraftModel = new DraftModel();
            $Draft = $DraftModel->get($Session->UserID, 0, 1, $sender->Discussion->DiscussionID)->firstRow();
            $sender->Form->addHidden('DraftID', $Draft ? $Draft->DraftID : '');
            if ($Draft && !$sender->Form->isPostBack()) {
                $sender->Form->setValue('Body', $Draft->Body);
                $sender->Form->setValue('Format', $Draft->Format);
            }
        }

        // Deliver JSON data if necessary
        if ($sender->deliveryType() != DELIVERY_TYPE_ALL) {
            $sender->setJson('LessRow', $sender->Pager->toString('less'));
            $sender->setJson('MoreRow', $sender->Pager->toString('more'));
            $sender->View = 'comments';
        }

        // Inform moderator of checked comments in this discussion
        $CheckedComments = $Session->getAttribute('CheckedComments', array());
        if (count($CheckedComments) > 0) {
            ModerationController::informCheckedComments($sender);
        }

        // Add modules
        $sender->addModule('DiscussionFilterModule');
        $sender->addModule('NewDiscussionModule');
        $sender->addModule('CategoriesModule');
        $sender->addModule('BookmarkedModule');

        $sender->CanEditComments = Gdn::session()->checkPermission('Vanilla.Comments.Edit', true, 'Category', 'any') && c('Vanilla.AdminCheckboxes.Use');

        // Report the discussion id so js can use it.
        $sender->addDefinition('DiscussionID', $DiscussionID);
        $sender->addDefinition('Category', $sender->data('Category.Name'));

        $sender->fireEvent('BeforeDiscussionRender');

        $AttachmentModel = AttachmentModel::instance();
        if (AttachmentModel::enabled()) {
            $AttachmentModel->joinAttachments($sender->Data['Discussion'], $sender->Data['Comments']);

            $sender->fireEvent('FetchAttachmentViews');
            if ($sender->deliveryMethod() === DELIVERY_METHOD_XHTML) {
                require_once $sender->fetchViewLocation('attachment', 'attachments', 'dashboard');
            }
        }

        $sender->render('index', 'discussion', 'vanilla');
    }

    /**
     * Totally untested...
     */
    public function pluginController_leakToggle_create($sender, $discussionID, $userID) {
// Test.
// Gdn::userMetaModel()->setUserMeta(3, 'LeakedDiscussions', array(15, 20, 40, 44));
// Gdn::cache()->flush();
        $discussionID = (int)$discussionID;
        $userID = (int)$userID;
        if ($userID <= 0) {
            return false;
        }

        // Should be simple somehow...
        if (!Gdn::session()->checkPermission('Leak.Allow')) {
            $discussionModel = new discussionModel();
            $discussion = $discussionModel->getID($discussionID);
            if(
                !(
                    c('leak.AllowDiscussionAuthor') == true && 
                    Gdn::session()->UserID == $discussion->InsertUserID
                )
            ) {
                return;
            }
        }
        $userMetaModel = new userMetaModel();

        $leakedDiscussions = $userMetaModel->getUserMeta((int)$userId, 'LeakedDiscussions', array())['LeakedDiscussions'];
        if (in_array($discussionID, $leakedDiscussions)) {
            $leakedDiscussions = array_diff($leakedDiscussions, array($discussionID));
        } else {
            $leakedDiscussions = array_merge($leakedDiscussions, array($discussionID));
        }
        $userMetaModel->setUserMeta($userId, 'LeakedDiscussions', $leakedDiscussions);
    }
}
