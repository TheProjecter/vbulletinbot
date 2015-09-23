# Introduction #

Command instructions for the bot. You can send multiple commands to the bot with one IM by seperating the commans using a semicolon. For example: `/;1;lt`


# Details #

### Forum Commands ###
  * / - go to root forum
  * . - go to parent forum of current forum
  * lf - list forums
  * mfr - mark the current forum as read

### Thread Commands ###
  * gt XX - go to threadid XX
  * lt - list first page of threads in the current forum
  * lt [ids](ids.md) - list threads with thread ids
  * lt XX - list the XXth page of threads in the current forum (ie. "lt 2" will list the second page of 5 threads in a forum)
  * lt XX YY - list the XXth page of YY threads per page in the current forum (ie. "lt 2 10" will list threads 11 thru 20 in the current forum)
  * mtr - mark the current thread as read
  * nt - create a new thread
  * rq - reply and quote current post
  * r - reply to current thread

### Post Commands ###
  * XX - XX will return the post at index XX (ie. "3" will return the third post of the current thread)
  * XX b - same "XX" but shows bbcode
  * lp - list the first page of posts in the current thread
  * lp XX - list the XXth page of posts in the current thread
  * lp XX YY - list the XXth page of YY posts per page in the current thread
  * cp - read current post
  * cp b - read current post and show bbcode
  * n - read the next post
  * p - read the previous post

### Misc ###
  * im [on|off] - turns IM Notifications on or off
  * sub - subscribe to current thread
  * unsub - unsubscribe to current thread
  * unsub all - unsubscribe to all threads
  * whereami - show your current forum and thread location
  * whoami - shows your vbulletin user info