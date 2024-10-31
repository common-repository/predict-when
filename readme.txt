=== Predict When ===
Contributors: Cojecto, ianhaycox
Donate link: http://predictwhen.com/
Tags: prediction, pronostic, tipp, event, crowd, chart, predict, crowd prediction, guess, predictwhen.com, predict when
Requires at least: 3.3
Tested up to: 3.5
Stable tag: 1.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Allow users to predict when an event will occur. Tap into the collective wisdom of your readers to predict future events and chart those predictions.

== Description ==

PredictWhen is a plugin that allows Wordpress blog owners to tap into the wisdom of crowds and
poll predictions from their readers as to when an event of their choosing will occur. The plugin
calculates the average of these predictions and presents a date, or date range, when the event is
likely to occur. It also generates a graph to chart the spread of predictions in a graphical format.

Example predictions created by blog owners might be:

* Predict when will man walk on the moon again?
* When will polio be eradicated?
* Predict when Apple will announce the iPhone 5?

= How it works =

PredictWhen has been built to harness the 'wisdom of crowds'.

Find more information on the theory and view a [short video](http://www.predictwhen.com/how-predictwhen-works/) explaining the concept.

= Closing a question =

Once an event has occurred, the question is closed and the actual date the event happened is entered.  The chart then
shows the actual event date alongside the estimated date based on the submitted predictions.

This provides interesting data on the accuracy of the blog reader’s prediction.

= Scoring = 

If user registration is required for a question, then closing a question will assign a score to each users prediction based
on the accuracy of their guess. To display a ranking table of users scores see the Usage section.

Blog owners can use the scoring functionality to incentivize their readers to make considered predictions
or to include a competition mechanic for pride or prizes.


= PredictWhen Directory =

The plugin has a sister website [PredictWhen.com](http://www.predictwhen.com) which acts as a directory of published
questions. An option within the plugin allows you to feature your question within the directory.

By listing your question on PredictWhen.com we aim to collate all the predictions powered by this plugin and give them more exposure.
We list your question and link to the page on your blog where it is published, users must visit your blog to make their prediction.

= User submitted questions =

The plugin provides the ability for logged-in users to submit their own question to the administrator for consideration.

Questions submitted by users are subject to approval by the blog administrator.

You can invite your readers to suggest their own questions or choose not to promote this feature. 

== Installation ==

To install the plugin complete the following steps

1. Use the automatic plugin installer or unzip the zip-file and upload the content to your Wordpress Plugin directory. Usually `/wp-content/plugins`
2. Activate the plugin via the Admin plugin page.
3. Create a prediction question from the admin menu
4. Create or edit a post/page and add the shortcode `[predictwhen id=1]`

== Frequently Asked Questions ==

= How do I display the Question and Chart ? =

To display the question and chart in a post use the shortcode `[predictwhen id=n]` where `n` is the ID of the question.

The ID above is shown next to the list of Questions in the plugin's admin screens.

For additional shortcode options see the Usage section.

= Why are my charts are not displayed ? =

The most probable reason is a Javascript error in another plugin or theme prevent the Google Chart API functioning correcly.

Check your browser's error log for any messages that might indicate the cause. In addition deactivating other plugins or using
the default Wordpress theme can help isolate the issue. If you need to report the issue please include a link to the page
with the chart.

= How do I change the chart colors ? =

Visit the plugin's settings page to change the colors of each of the chart bar types.

= The chart is not showing the crowd prediction dates =

In order to calculate the expected date of an event the plugin requires at least 10 predictions.  More than 30 predictions will
increase the accuracy of the expected event date significantly.

= Why should I publish my question to the PredictWhen directory ? =

By publishing your question to PredictWhen.com your blog receives more exposure and a link to the post or page hosting the question.

Users can only predict the date of an event on your site, PredictWhen.com acts as a directory of all published questions.

= What data is sent to PredictWhen.com ? =

Only anonymized summary data is sent to the PredictWhen directory.  No usernames, email addresses or personally identifable data is sent.

= Additional FAQ's =

Please see [http://www.predictwhen.com/plugin-faqs/](http://www.predictwhen.com/plugin-faqs/) for more information.

== Usage ==

Within the Wordpress admin page select 'Add New Question' from the 'Predict When' menu.

Enter the Question you wish to present to your users. Keep your question short & simple. If you need to further qualify the question do so in the main body of the post.

Use the date range limits to prevent predictions before or after the specified dates.  Leave either field blank to indicate no limit.

Check the 'Never' option to allow users to indicate that the event will never happen.

If you require users to be logged in to make a prediction check the 'Login or register' box.

= Shortcodes =

Use the following shortcodes in a post or page.

**Chart of predictions**

`[predictwhen id=n]` - Display the question referenced by the question ID `n` along with a chart of
predictions and invite users to make their own prediction.

If you do not want to display a chart, but just invite predictions add the option `hide_chart=1`, e.g. `[predictwhen id=x hide_chart=1]`

**Scoring**

`[predictwhen id=n scoring=1]` - Once a question has been closed you can display a ranking table of scores based on
the accuracy of predictions for each user. Note the option 'Login or register' must be checked to calculate scores.

Additional options:

* show_prediction
* show_when
* limit

For example to display a ranking table showing each users score, the predicted date, when the prediction was made, limited
to the top 10 scorers use the following `[predictwhen id=n scoring=1 show_prediction=1 show_when=1 limit=10]`


**User questions**

`[predictwhen user_question=1]` - Include this shortcode in a post to provide the ability for a user to submit a question.

Users must be logged-in to submit a question for consideration.  The administrator will receive a notification email
of a new question and has the ability to approve or reject the submission.


= Known Issues =

Dates beyond the year 2038 are unlikely to work due to a restriction in the Unix time format.


== Screenshots ==

1. Open question prompting for a predicted date
2. Closed question with the event date shown
3. List of questions in the admin menu
4. Adding a new question
5. User facing form to submit a suggested question
6. Scoring
7. Settings

== Changelog ==

= 1.3 - 15 January 2013 =
* Force repository update

= 1.2 - 15 January 2013 =
* Updates for Wordpress 3.5

= 1.1 - 25 October 2012 =
* Disable entry fields for user question form if not logged in.
* Make the 'Must log in' message more prominent
* Allow predictions for a month (useful for long range predictions) or a day
* Bug fix - Update predictwhen.com with predictions if an existing question is published
* Improved algorithm to calculate crowd prediction
* Removed confusing confidence range from chart


= 1.0 - 12 September 2012 =
* Initial version

== Upgrade Notice ==

Please deactivate and reactivate the plugin to update the database schema.