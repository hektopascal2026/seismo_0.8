<?php

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Mail\MailModule;

/** Newsletter admin — {@see MailModuleHandler} with {@see MailModule::newsletter()}. */
final class NewsletterController
{
    private function handler(): MailModuleHandler
    {
        return new MailModuleHandler(MailModule::newsletter());
    }

    public function show(): void
    {
        $this->handler()->show();
    }

    public function saveSubscription(): void
    {
        $this->handler()->saveSubscription();
    }

    public function analyzeBoilerplate(): void
    {
        $this->handler()->analyzeBoilerplate();
    }

    public function deleteSubscription(): void
    {
        $this->handler()->deleteSubscription();
    }

    public function disableSubscription(): void
    {
        $this->handler()->disableSubscription();
    }

    public function reprocessSubscription(): void
    {
        $this->handler()->reprocessSubscription();
    }

    public function moveToMail(): void
    {
        $this->handler()->moveToOtherModule();
    }
}
