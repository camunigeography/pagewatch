<?php

/*
	Invoke using
	wget --quiet --delete-after --user-agent="Pagewatch" https://example.com/path/to/pagewatch/runcheck.html
	or preferably PHP from the command-line, which would be
	php -d include_path=/path/to/common/libraries/ -f /var/www/example.com/pagewatch/index.html '&action=runcheck'
*/


# Define a class implementing a pagewatch facility
require_once ('frontControllerApplication.php');
class pagewatch extends frontControllerApplication
{
	# Function to assign defaults additional to the general application defaults
	public function defaults ()
	{
		# Specify available arguments as defaults or as NULL (to represent a required argument)
		$defaults = array (
			'applicationName'					=> 'Pagewatch',
			'div'								=> 'pagewatch',
			'database'							=> 'pagewatch',
			'administrators'					=> 'administrators',
			'table'								=> ($_SERVER['SERVER_NAME'] == 'localhost' ? NULL : str_replace ('.', '_', $_SERVER['SERVER_NAME'])),	// E.g. would be www_example_com
			'allowExternalWatches'				=> false,						# Whether to allow external watches (this will not affect any currently in the database)
			'webmasterSendSummary'				=> true,						# Whether to send the webmaster summaries
			'webmasterSendSummaryEmpty'			=> false,						# Whether still to send summaries even if no changes made
			'siteName'							=> 'website',					# The name of the site (e.g. 'XYZ website')
			'sourceWidgetDescription'			=> "'Watch for changes' link",	# Description of what the user should click on within the site
			'internalRetrievalPreferred'		=> false,						# Whether internal retrieval (server file opening) is preferred to http retrieval
			'checkTimePeriod'					=> '24 hours',					# Description of the time check period which is called by the scheduler (e.g. Cron (on Unix) or Windows Scheduler)
			'unavailabilityRemovalThreshold'	=> 3,							# The number of times when a page should be removed due to unavailability
			'bannedPages'						=> array (),					# List of banned pages which cannot be watched, each starting from the site root (/); the wildcard * can be used at the end of a listed page
			'emailSuffix'						=> '@cam.ac.uk',				# Suffix to append to username
			'tabUlClass'						=> 'tabsflat',
		);
		
		# Return the defaults
		return $defaults;
	}
	
	
	# Function to assign additional actions
	public function actions ()
	{
		# Specify additional actions
		$actions = array (
			'watch' => array (
				'description' => 'Create a new watch',
				'url' => 'watch/',
				'tab' => 'Create new watch',
				'icon' => 'add',
				'authentication' => true,
			),
			'subscriptions' => array (
				'description' => 'Show all subscriptions',
				'url' => 'subscriptions.html',
				'tab' => 'My subscriptions',
				'icon' => 'application_view_list',
				'authentication' => true,
			),
			'runcheck' => array (
				'description' => 'Check for changes',
				'url' => 'runcheck.html',
				'usetab' => 'admin',
				'export' => true,
			),
			'clearance' => array (
				'description' => 'Clear all changes (no e-mails sent)',
				'url' => 'clearance.html',
				'parent' => 'admin',
				'subtab' => 'Clearance',
				'authentication' => true,
				'administrators' => true,
			),
			'dumpdata' => array (
				'description' => 'List all watches',
				'url' => 'dumpdata.html',
				'subtab' => 'List all watches',
				'parent' => 'admin',
				'authentication' => true,
				'administrators' => true,
			),
			'unsubscribe' => array (
				'description' => 'Unsubscribe bouncers',
				'parent' => 'admin',
				'subtab' => 'Unsubscribe bouncers',
				'authentication' => true,
				'administrators' => true,
			),
		);
		
		# Return the actions
		return $actions;
	}
	
	
	
	# Home page
	public function home ()
	{
		$this->watch ();
		echo "\n" . "\n<div class=\"graybox\">";
		echo "\n\t" . '<h3>Check subscriptions</h3>';
		echo "\n\t" . "<p>Click on the <a href=\"{$this->baseUrl}/subscriptions.html\">My subscriptions</a> tab to see pages currently being watched for you or to unsubscribe from one.</p>";
		echo "\n" . "\n</div>";
	}
	
	
	# Wrapper function to do a check which clears all current changes
	public function clearance ()
	{
		$this->runcheck ($clearance = true);
	}
	
	
	# Function to run a check on watched pages
	public function runcheck ($clearance = false)
	{
		# Check that the run is being done only at allowed times
		if (!$clearance && !$this->runCheckTimeValid ()) {
			echo "\n" . '<p>Apologies, but, for security reasons, this can only be run at certain times.</p>';
			return false;
		}
		
		# Get all the data or end
		if (!$watches = $this->databaseConnection->select ($this->settings['database'], $this->settings['table'], array (), array (), true, 'id')) {
			echo "\n" . '<p>There are currently no pages being watched.</p>';
			return false;
		}
		
		# Get a uniqued list of the URLs
		foreach ($watches as $watch) {
			$urls[$watch['url']] = $watch['md5'];
		}
		
		# Check for changes on each URL
		list ($changedPages, $deletedPages) = $this->getChangesAndDeletions ($urls);
		
		# Compile the alerts
		$alerts = $this->compileAlerts ($watches, $changedPages, $deletedPages);
		
		# Mail out the alerts
		$emailsSent = 0;
		if (!$clearance) {
			$emailsSent = $this->mailAlerts ($alerts, $watches);
		}
		
		# Create messages for pages changed and e-mails sent out
		$messagePagesChangedOrDeleted  = 'Of the ' . count ($urls) . ' page' . (count ($urls) > 1 ? 's' : '') . ' being watched, ' . count ($changedPages) . (count ($changedPages) != 1 ? ' pages' : ' page') . ' had changed and ' . count ($deletedPages) . (count ($deletedPages) != 1 ? ' pages' : ' page') . ' have been removed.';
		if ($changedPages) {$messagePagesChangedOrDeleted .= "\nChanged pages:\n\n" . implode ("\n" , $changedPages) . "\n\n";}
		if ($deletedPages) {$messagePagesChangedOrDeleted .= "\nDeleted pages:\n\n" . implode ("\n" , $deletedPages) . "\n\n";}
		if (!$clearance) {$messageEmailsSent = $emailsSent . ($emailsSent != 1 ? ' e-mails were' : ' e-mail was') . ' sent.';}
		
		# If administartor summaries are allowed, send the administrator a summary if empty summaries are permissable or if e-mails were sent
		$administratorSummary = ($this->settings['webmasterSendSummary'] && ($this->settings['webmasterSendSummaryEmpty'] || $emailsSent));
		
		# Send the administrator a summary if required
		if ($administratorSummary && !$clearance) {
			$message  = "\n" . 'Dear Pagewatch administrator,';
			$message .= "\n\n" . 'The Pagewatch facility on the ' . $this->settings['siteName'] . ' has just been run' . ($clearance ? ' as a clearance, so no e-mails were sent' : '') . '.';
			$message .= "\n\n" . ' - ' . $messagePagesChangedOrDeleted;
			if (!$clearance) {$message .= "\n\n" . ' - ' . $messageEmailsSent;}
			
			# Send the e-mail and increment the counter
			$this->sendEmail ($this->settings['webmaster'], 'Summary of updates sent', $message);
		}
		
		# Complete the HTML by showing the results if running interactively
		if ($clearance) {
			$html = "\n<p>A check has been run" . ($clearance ? ' as a clearance, so <strong>no e-mails were sent</strong>' : '') . '.</p>';
			$list[] = $messagePagesChangedOrDeleted;
			if (!$clearance) {$list[] = $messageEmailsSent;}
			$html .= application::htmlUl ($list);
			echo $html;
		}
	}
	
	
	# Function to get the watches
	private function getChangesAndDeletions ($urls)
	{
		# Check for changes on each URL
		#!# Change updates to be updateMany rather than within a loop
		$changedPages = array ();
		$deletedPages = array ();
		foreach ($urls as $url => $databaseMd5) {
			$urlQuoted = $this->databaseConnection->quote ($url);
			
			# Find the highest unavailability count for that URL
			$query = 'SELECT MAX(unavailableCount) as unavailableCount FROM ' . $this->dataSource . " WHERE url = {$urlQuoted};";
			if (!$data = $this->databaseConnection->getOne ($query)) {
				$this->throwError ($query);
				break;
			}
			$highestUnavailabilityCount = $data['unavailableCount'];
			$newUnavailabilityCount = ($highestUnavailabilityCount + 1);
			
			# Attempt to get the file contents
			if (!$contents = $this->getPageContents ($url, $this->settings['internalRetrievalPreferred'])) {
				
				# Remove the page if the threshold has been reached
				if ($newUnavailabilityCount == $this->settings['unavailabilityRemovalThreshold']) {
					if (!$this->databaseConnection->delete ($this->settings['database'], $this->settings['table'], array ('url' => $url))) {
						$this->throwError ($query);
					}
					$deletedPages[] = $url;
				} else {
					
					# Otherwise increment the unavailableCount in the database or throw a non-stop error
					if (!$this->databaseConnection->update ($this->settings['database'], $this->settings['table'], array ('unavailableCount' => $newUnavailabilityCount), array ('url' => $url))) {
						$this->throwError ($query);
					}
				}
				
			# If the file can be retrieved OK ...
			} else {
				
				# Reset the unavailability count to zero if necessary (or throw a non-stop error)
				if ($highestUnavailabilityCount != 0) {
					if (!$this->databaseConnection->update ($this->settings['database'], $this->settings['table'], array ('unavailableCount' => '0'), array ('url' => $url))) {
						$this->throwError ($query);
					}
				}
				
				# Get an md5 of the contents
				$nowMd5 = md5 ($contents);
				if ($nowMd5 != $databaseMd5) {
					
					# Add to the array of changed pages
					$changedPages[] = $url;
					
					# Update the database for each such page or throw a non-stop error
					if (!$this->databaseConnection->update ($this->settings['database'], $this->settings['table'], array ('md5' => $nowMd5), array ('url' => $url))) {
						$this->throwError ($query);
					}
				}
			}
		}
		
		# Return the results
		return array ($changedPages, $deletedPages);
	}
	
	
	# Function to compile the alerts
	private function compileAlerts ($watches, $changedPages, $deletedPages)
	{
		# Start an array to hold the watched page updates and deletions for each person
		$alerts = array ();
		
		# Loop through each watch and check whether the page has been updated or deleted
		foreach ($watches as $watchId => $watchAttributes) {
			
			# If updated, add to a list of updates
			if (in_array ($watchAttributes['url'], $changedPages)) {
				$alerts[$watchAttributes['email']]['updates'][$watchAttributes['url']] = $watchId;
			} else {
				
				# If deleted, add to a list of deletions
				if (in_array ($watchAttributes['url'], $deletedPages)) {
					$alerts[$watchAttributes['email']]['deletions'][$watchAttributes['url']] = $watchId;
				}
			}
		}
		
		# Return the result
		return $alerts;
	}
	
	
	# Function mail out the alerts to users
	private function mailAlerts ($alerts, $watches)
	{
		# E-mail each person their list of watches, counting of the total number of e-mails sent
		$emailsSent = 0;
		foreach ($alerts as $email => $changes) {
			
			# Ensure there are sub-arrays of updates and deletions
			if (!isSet ($changes['updates'])) {$changes['updates'] = array ();}
			if (!isSet ($changes['deletions'])) {$changes['deletions'] = array ();}
			
			# Get the name of the person from the most recent watch
			$mostRecentWatch = max (array_values (array_merge ($changes['updates'], $changes['deletions'])));
			
			# Construct the array of changed pages for the person
			$updates = array ();
			$itemNumber = 1;
			foreach ($changes['updates'] as $item) {
				$updates[] = $itemNumber++ . ": '" . ($watches[$item]['title'] != '' ? $watches[$item]['title'] : '[No title]') . "' at the address:" . "\n   " . $watches[$item]['url'];
			}
			
			# Construct the array of deleted pages for the person
			$deletions = array ();
			$itemNumber = 1;
			foreach ($changes['deletions'] as $item) {
				$deletions[] = $itemNumber++ . ": '" . ($watches[$item]['title'] != '' ? $watches[$item]['title'] : '[No title]') . "' at the address:" . "\n   " . $watches[$item]['url'];
			}
			
			# Re-query the database to get an updated list of items being watched and the user's name
			$query = 'SELECT * FROM ' . $this->dataSource . " WHERE email = '" . $this->databaseConnection->escape ($email) . "';";
			$data = $this->databaseConnection->getData ($query, "{$this->settings['database']}.{$this->settings['table']}");
			
			# Obtain the name if required
			$name = ($this->settings['useCamUniLookup'] && $this->user && ($userLookupData = camUniData::getLookupData ($this->user)) ? $userLookupData['name'] : false);
			
			# Construct the e-mail message, taking the most recent name as the name
			$message  = '';
			if ($name) {$message .= "\n" . 'Dear ' . $name . ',';}
			if (!empty ($updates)) {
				$message .= "\n\n" . 'The following page' . (count ($updates) > 1 ? 's' : '') . ' being watched for you ' . (count ($updates) > 1 ? 'have' : 'has') . ' been updated recently:';
				$message .= "\n\n" . implode ("\n\n", $updates);
			}
			if (!empty ($deletions)) {
				$message .= "\n\n" . 'The following page' . (count ($deletions) > 1 ? 's' : '') . (count ($deletions) > 1 ? ' are no longer being watched as they appear' : ' is no longer being watched as it appears') . ' to have been deleted:';
				$message .= "\n\n" . implode ("\n\n", $deletions);
			}
			$message .= "\n\n\n" . '--';
			#!# Giving http:// rather than https://
			$message .= "\n" . 'You have received this e-mail because you requested notification of changes to specific pages on the ' . $this->settings['siteName'] . ". You can add/remove watches at\n" . $_SERVER['_SITE_URL'] . $this->baseUrl . '/';
			
			# Send the e-mail and increment the counter
			if ($this->sendEmail ($email, 'Pages changed', $message)) {
				$emailsSent++;
			}
		}
		
		# Return the number of e-mails sent
		return $emailsSent;
	}
	
	
	# Function to dump current data to screen
	public function dumpdata ()
	{
		# Start the HTML
		$html  = "\n<p>This page produces a straight dump out of the database.</p>";
		$html .= "\n<p>You can <a href=\"{$this->baseUrl}/unsubscribe.html\">delete users whose e-mails are bouncing</a>.</p>";
		
		# Get the data
		$query = 'SELECT * FROM ' . $this->dataSource . ' ORDER BY id;';
		$watches = $this->databaseConnection->getData ($query, "{$this->settings['database']}.{$this->settings['table']}");
		
		# Exit if no watches
		if (!$watches) {
			echo $html . '<p>There are currently no pages being watched.</p>';
			return false;
		}
		
		# Get the table heading substitutions
		$headings = $this->databaseConnection->getHeadings ($this->settings['database'], $this->settings['table']);
		
		# Turn the raw data into a table
		$html .= application::htmlTable ($watches, $headings, $class = 'lines compressed small', $showKey = false);
		
		# Echo the HTML
		echo $html;
	}
	
	
	# Function to unsubscribe bouncing users
	public function unsubscribe ()
	{
		# Get the list of users
		$query = "SELECT DISTINCT username FROM {$this->dataSource} ORDER BY username;";
		$users = $this->databaseConnection->getPairs ($query);
		
		# Confirmation form
		$form = new form (array (
			'formCompleteText' => false,
			'div' => false,
			'requiredFieldIndicator' => false,
		));
		$form->heading ('p', 'This form lets you remove the watches of a user whose e-mail address is now bouncing. Just select then confirm their username.');
		$form->select (array (
			'name'	=> 'username',
			'title'	=> 'Select user to remove',
			'required' => true,
			'values' => $users,
			'autofocus' => true,
		));
		$form->input (array (
			'name'			=> 'confirm',
			'title'			=> 'Type username to confirm',
			'required'		=> true,
			'discard'		=> true,
		));
		$form->validation ('same', array ('username', 'confirm'));
		if (!$result = $form->process ()) {return false;}
		
		# Delete the watches
		if (!$this->databaseConnection->delete ($this->settings['database'], $this->settings['table'], $result)) {
			echo "\n<p class=\"warning\">There was a problem deleting the watches of user " . htmlspecialchars ($result['username']) . ".</p>";
			return false;
		}
		
		# Confirm success
		echo "\n<p>The watches of user " . htmlspecialchars ($result['username']) . ' have been deleted.</p>';
	}
	
	
	# Function to list currently watched pages
	public function subscriptions ()
	{
		# Get the list for the user
		if (!$data = $this->databaseConnection->select ($this->settings['database'], $this->settings['table'], array ('username' => $this->user), array ('id', 'url', 'title'), $associative = true, $orderBy = 'title,url')) {
			echo "\n<p>No pages are currently being watched for you. You can <a href=\"{$this->baseUrl}/watch/\">create a new watch</a>.</p>";
			return;
		}
		
		# Rearrange the data
		$widgetPrefix = 'item';
		foreach ($data as $id => $watch) {
			$watches[$id]['Page title'] = "<a href=\"{$watch['url']}\"><strong>" . htmlspecialchars ($watch['title']) . '</strong></a>';
			$watches[$id]['URL'] = "<a href=\"{$watch['url']}\">" . application::urlPresentational ($watch['url']) . '</a>';
			$widgets[$id] = $widgetPrefix . $id;
			$watches[$id]['Unsubscribe?'] = '{' . $widgets[$id] . '}';
		}
		
		# Start the HTML
		$html = '';
		
		# Assemble the list into the template
		$template  = "\n<p>The pages listed below are being watched for you.</p>";
		$template .= "\n<p><strong>To unsubscribe:</strong> click on the relevant item(s) and click the submit button at the end.</p>";
		$template .= application::htmlTable ($watches, array (), $class = 'lines', $showKey = false, false, true);
		
		# Create the form to convert the listing into an administration page
		$form = new form (array (
			'displayRestrictions' => false,
			'display' => 'template',
			'displayTemplate' => '{[[PROBLEMS]]}' . $template . '<p>{[[SUBMIT]]}</p>',
			'requiredFieldIndicator' => false,
			'formCompleteText' => false,
		));
		foreach ($widgets as $widget) {
			$form->checkboxes (array (
				'name'		=> '' . $widget,
				'title'		=> 'Unsubscribe?',
				'values'	=> array ('Unsubscribe?'),
				'output'	=> array ('processing' => 'compiled'),
			));
		}
		if (!$result = $form->process ($html)) {
			echo $html;
			return false;
		}
		
		# Loop through each checked one and unsubscribe the user
		$ids = array ();
		foreach ($result as $widget => $checked) {
			if ($checked) {
				$ids[] = str_replace ($widgetPrefix, '', $widget);
			}
		}
		
		# Remove the requested items
		$this->databaseConnection->deleteIds ($this->settings['database'], $this->settings['table'], $ids);
		
		# Refresh to the same page
		application::sendHeader ('refresh');
		
		# Echo the HTML
		echo $html;
	}
	
	
	# Function to create a new watch entry
	public function watch ()
	{
		# Determine the chosen address; $_GET cannot be used as mod_rewrite does not supply the query string in this way
		$chosen = preg_replace ('/^action=watch(&?)/', '', urldecode ($_SERVER['QUERY_STRING']));
		
		# If there is no ID supplied, then detect the referrer and redirect to a GET containing it
		#!# Refactor out this section as a separate method
		if (empty ($chosen)) {
			
			# Obtain the referring page (which may be posted)
			$referrer = $_SERVER['HTTP_REFERER'];
			
			# Ensure that the visitor as followed a link to the page (and that the link is not from the pagewatch area)
			$delimiter = '!';
			$pagewatchArea = preg_match ($delimiter . '^' . preg_quote ($_SERVER['_SITE_URL'] . $this->baseUrl, $delimiter) . $delimiter . 'i', $referrer);
			if (!$referrer || $pagewatchArea) {
				echo "\n" . '<p>This facility enables you to sign up to receive a quick e-mail update when a page on this site has been updated.</p>';
				echo "\n\n" . "<div class=\"graybox\">";
				echo "\n\t" . '<h3>Add a new page to watch</h3>';
				echo "\n\t" . "<p><strong>To watch a page:</strong>";
				echo "\n\t" . "<ol>\n\t\t<li>Go to a page on this site you'd like to 'watch'</li>\n\t\t<li>Click on " . $this->settings['sourceWidgetDescription'] . "</li>\n</ol>";
				echo "\n\n" . "</div>";
				return;
			}
			
			# Determine the page to submit to
			$redirectTo = $_SERVER['_SITE_URL'] . $this->baseUrl . '/watch/?' . urlencode (preg_replace ($delimiter . '^' . preg_quote ($_SERVER['_SITE_URL'], $delimiter) . $delimiter, '', $referrer));
			
			# Redirect to that page, which will trigger Raven authentication
			application::sendHeader (302, $redirectTo);
			return false;
		}
		
		# If external watching is not allowed, ensure the link they followed was from within the site
		if (!$this->settings['allowExternalWatches']) {
			#!# This would add http://<hostname> at the start if the URL already starts with the site's hostname
			$testAgainst = (substr ($chosen, 0, 1) == '/' ? $_SERVER['_SITE_URL'] : '') . $chosen;
			if (!application::urlIsInternal ($testAgainst)) {
				echo '<p class="error">Sorry, pages to watch must be from within this site!</p>';
				return false;
			}
		}
		
		# Ensure that the link they followed is not from a banned page, by looping through the list of banned pages, taking into account wildcard-ending pages
		if ($this->pageBanned ($this->settings['bannedPages'], $chosen)) {
			echo '<p class="error">Apologies, but for technical reasons, it is not possible to have that particular page watched.</p>';
			return false;
		}
		
		# Assemble the chosen page URL
		$data['url'] = $_SERVER['_SITE_URL'] . urldecode ($chosen);
		
		# Now that a valid page has been confirmed, get the file contents
		if (!$contents = $this->getPageContents ($data['url'], $this->settings['internalRetrievalPreferred'])) {
			echo '<p class="error">You appear to have requested a file that does not exist or temporarily cannot be retrieved by pagewatch.</p>';
		}
		
		# Cache the username and e-mail
		$data['username'] = $this->user;
		$data['email'] = $this->user . $this->settings['emailSuffix'];
		
		# Check against the username & url pair to see whether the user is already watching this page and exit if so
		if ($result = $this->databaseConnection->select ($this->settings['database'], $this->settings['table'], $data)) {
			echo "<p>You have already registered to watch that page.</p>";
			echo "\n<p><a href=\"{$this->baseUrl}/subscriptions.html\"><img src=\"/images/icons/application_view_list.png\" alt=\"List\" class=\"icon\" border=\"0\" /> List all pages being watched for me.</a></p>";
			return;
		}
		
		# Get the title from the contents; Note: this will fail if there is more than one <h1> tag!
		$data['title'] = application::getTitleFromFileContents ($contents);	// Will *not* be entity-encoded
		$data['title'] = ($data['title'] ? $data['title'] : '[No title]');
		
		# Create the form and apply settings
		$form = new form (array (
			'displayDescriptions' => false,
			'formCompleteText' => false,
			'submitButtonText' => 'Confirm',
			'requiredFieldIndicator' => false,
			'div' => 'graybox confirm',
		));
		$form->heading ('', '<strong>I wish to receive an e-mail when the page <a href="' . htmlspecialchars ($data['url']) . '" target="_blank" title="[Link opens in new window]">' . htmlspecialchars ($data['title']) . '</a> has been updated.</strong>');
		$form->heading ('', "<p>Notifications will be sent to: {$this->user}{$this->settings['emailSuffix']}</p>");
		$form->hidden (array (
			'values' => array ('url' => $data['url'],),
		));
		if (!$discard = $form->process ()) {return;}
		
		# Add in the user's real name for ease of administration purposes
		$data['name'] = $name = ($this->settings['useCamUniLookup'] && $this->user && ($userLookupData = camUniData::getLookupData ($this->user)) ? $userLookupData['name'] : false);
		
		# Add in an MD5 hash of the contents
		$data['md5'] = md5 ($contents);
		
		# Insert the data into the database or throw a stop error
		if (!$insert = $this->databaseConnection->insert ($this->settings['database'], $this->settings['table'], $data)) {
			$this->throwError (NULL, 'A technical error occured while trying to add the watch.');
			return false;
		}
		
		# Get the automatic ID
		$input['id'] = $this->databaseConnection->getLatestId ();
		
		# Confirm insertion
		echo "\n" . '<p>You are now subscribed to receive an e-mail when the page <a href="' . htmlspecialchars ($data['url']) . '">' . htmlspecialchars ($data['title']) . '</a> is updated. Pages are checked for changes every ' . $this->settings['checkTimePeriod'] . '.</p>';
		echo "\n<p><a href=\"{$this->baseUrl}/subscriptions.html\"><img src=\"/images/icons/application_view_list.png\" alt=\"List\" class=\"icon\" border=\"0\" /> List all pages being watched for me.</a></p>";
	}
	
	
	# Function to check that the time is valid
	private function runCheckTimeValid ()
	{
		# Only allow running the check between 00:00 and 00:10
		return ((date ('G') == 0) && (date ('i') < 10));
	}
	
	
	# Function to check whether a page is banned
	private function pageBanned ($bannedPages, $currentPage)
	{
		# Loop through each banned page and return true if banned
		foreach ($bannedPages as $bannedPage) {
			if (substr ($bannedPage, -1) == '*') {
				$delimiter = '!';
				if (preg_match ($delimiter . '^' . preg_quote (substr ($bannedPage, 0, -1), $delimiter) . $delimiter, $currentPage)) {
					return true;
				}
			} else {
				if ($currentPage == $bannedPage) {
					return true;
				}
			}
		}
		
		# Otherwise return no problems
		return false;
	}
	
	
	# Function to get the contents of a file; note that handling of a false result (i.e. a 404) should be dealt with by the calling function
	private function getPageContents ($url, $internalRetrievalPreferred = true)
	{
		# Return false if the supplied argument is not a valid URL
		if (!application::urlSyntacticallyValid ($url)) {return false;}
		
		# Retrieve the file externally if the file is on a different server or, if not, if internal retrieval is required
		$retrievingInternally = (application::urlIsInternal ($url) && $internalRetrievalPreferred);
		
		# Force external retrieval if there is a query string
		if (strpos ($url, '?') !== false) {$retrievingInternally = false;}
		
		# If retrieving internally, convert the URL to an internal file
		if ($retrievingInternally) {$url = $this->convertUrlToInternalFile ($url, $this->settings['directoryIndex']);}
		
		# Clean up the URL if retrieving externally
		if (!$retrievingInternally) {$url = str_replace (' ', '%20', $url);}
		
		# Attempt to get the contents of the URL; return false if the file can't be obtained, i.e. a 404
		if (!$contents = @file_get_contents ($url)) {return false;}
		
		# Ignore marked "ignore-changes" sections
		$contents = preg_replace ('|<!-- ignore-changes -->.+<!-- /ignore-changes -->|U', '', $contents);	// Note use of U (PCRE_UNGREEDY)
		
		# Return the contents
		return $contents;
	}
	
	
	# Function to convert an external URL (which is actually on the same server) to an internal filename
	private function convertUrlToInternalFile ($url, $directoryIndex)
	{
		# Return false if the URL contains a query string
		if (strpos ($url, '?') !== false) {return false;}
		
		# Return the filename with the the server name removed and the server root added
		$delimiter = '!';
		$filename = $_SERVER['DOCUMENT_ROOT'] . preg_replace ($delimiter . '^' . preg_quote ($_SERVER['_SITE_URL'], $delimiter) . $delimiter, '', $url);
		
		# Add on the directory index file if necessary
		if (substr ($filename, -1) == '/') {$filename .= $directoryIndex;}
		
		# Return the filename
		return $filename;
	}
	
	
	# Function to throw an error
	public /* as per frontControllerApplication.php */ function throwError ($lastQuery = '', $message = '')
	{
		# Display the message if one is supplied or create the default one
		if (!empty ($message)) {
			echo "\n" . "<p class=\"error\">$message Please try again later. The webmaster has been informed of this problem.</p>";
		} else {
			$message = 'A problem occured while trying to make a change to the database.';
		}
		
		# Start the e-mail message
		$message = "\n" . $message;
		
		# Include in the e-mail message the last query if one has been supplied
		if (!empty ($lastQuery)) {
			$message .= "\n\n" . 'The query which generated this error was:';
			$message .= "\n\n" . $lastQuery;
		}
		
		# E-mail the administrator, including the site name in the message
		# $this->sendEmail ($this->settings['webmaster'], 'Database error', $message);
		application::sendAdministrativeAlert ($this->settings['webmaster'], 'Pagewatch', 'Pagewatch error', $message);
	}
	
	
	# Function to send an e-mail
	private function sendEmail ($recipient, $subject, $message, $headers = '')
	{
		# Define standard e-mail headers; NB [ or ] in the 'from' name is not possible
		$headers .= 'From: Pagewatch <' . $this->settings['webmaster'] . '>';
		$envelopeSender = '-f' . $this->settings['webmaster'];	// This is necessary so that runcheck (which is not instantiated by Apache) sets the Return-Path as webmaster@ rather than webserver@
		
		# Send the message and return success or failure
		return (application::utf8Mail ($recipient, '[Pagewatch] (' . $this->settings['siteName'] . ') ' . $subject, wordwrap ($message), $headers, $envelopeSender));
	}
}

?>
