<?php

/**
 * Batch process for sending newsletters.
 *
 * @package newsletter
 */
class NewsletterEmailProcess extends BatchProcess {

	protected $subject;
	protected $from;
	protected $newsletter;
	protected $nlType;
	protected $messageID;

	/**
	 * Set up a Newsletter Email Process
	 *
	 * @param $recipients DataObjectSet The recipients of this newsletter
	 */
	public function __construct ($subject, $from, $newsletter, $nlType, $recipients, $messageID = null) {
		$this->subject = $subject;
		$this->from = $from;
		$this->newsletter = $newsletter;
		$this->nlType = $nlType;
		$this->messageID = $messageID;

		parent::__construct($recipients);
	}

	public function getNewsletter() {
		return $this->newsletter;
	}

	public function next ($count = 10) {
		$numObjects = count($this->objects);
		$max = $this->current + $count;

		if($max > $numObjects) {
			$max = $numObjects;
		}

		while($this->current < $max) {
			$index = $this->current++;
			$member = $this->objects[$index];

	        // check to see if the user has unsubscribed from the mailing list
	        // TODO Join in the above query first
	        $filter = "\"MemberID\"='{$member->ID}' AND \"NewsletterTypeID\"='{$this->nlType->ID}'";
			$unsubscribeRecord = DataObject::get_one('UnsubscribeRecord', $filter);

	        if( !$unsubscribeRecord ) {
	    		$address = $member->Email;

	    		/**
	    		 * Email Blacklisting Support
	    		 */
				if($member->BlacklistedEmail && NewsletterEmailBlacklist::isBlocked($address)){
					$bounceRecord = new Email_BounceRecord();
					$bounceRecord->BounceEmail = $member->Email;
					$bounceRecord->BounceTime = date("Y-m-d H:i:s");
					$bounceRecord->BounceMessage = "BlackListed Email";
					$bounceRecord->MemberID = $member->ID;
					$bounceRecord->write();

					// Log the blacklist for this specific Newsletter
					$newsletter = new Newsletter_SentRecipient();
					$newsletter->Email = $address;
					$newsletter->MemberID = $member->ID;
					$newsletter->Result = 'BlackListed';
					$newsletter->ParentID = $this->newsletter->ID;
					$newsletter->write();
				} else {
					$e = new NewsletterEmail($this->newsletter, $this->nlType);
					$e->setSubject($this->subject);
					$e->setFrom($this->from);
					$e->setTemplate($this->nlType->Template);

					$this->sendToAddress($e, $address, $this->messageID, $member);
				}
	        }
    	}

	    return $this->current >= $numObjects ? $this->complete() : parent::next();
	}

	/**
	 * Sends a Newsletter email to the specified address
	 *
	 * @param $member The object containing information about the member being emailed
	 */
	private function sendToAddress ($email, $address, $messageID = null, $member) {
		$email->setTo($address);
		$result = $email->send($messageID, $member);
		// Log result of the send
		$newsletter = new Newsletter_SentRecipient();
		$newsletter->Email = $address;
		$newsletter->MemberID = $member->ID;
		
		// If sending is successful
		$newsletter->Result = ($result == true) ? 'Sent' : 'Failed'; 
		
		$newsletter->ParentID = $this->newsletter->ID;
		$newsletter->write();
		// Adding a pause between email sending can be useful for debugging purposes
		// sleep(10);
	}

	public function complete () {
		parent::complete();

		$resent = ($this->newsletter->SentDate) ? true : false; 

		$this->newsletter->SentDate = 'now';
		$this->newsletter->Status = 'Send';
		$this->newsletter->write();

		// Call the success message JS function with the Newsletter information
		if($resent) {
			return "resent_ok( '{$this->nlType->ID}', '{$this->newsletter->ID}', '".count( $this->objects )."' ); ";
		} else {
			return "draft_sent_ok( '{$this->nlType->ID}', '{$this->newsletter->ID}', '".count( $this->objects )."' ); ";
		}
	}
}