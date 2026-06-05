<?php

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Mail\MailModule;

/** Mail admin — {@see MailModuleHandler} with {@see MailModule::mail()}. */
final class MailController
{
    private function handler(): MailModuleHandler
    {
        return new MailModuleHandler(MailModule::mail());
    }

    public function show(): void
    {
        $this->handler()->show();
    }

    public function refreshMailIngest(): void
    {
        $this->handler()->refreshMailIngest();
    }

    public function saveSubscription(): void
    {
        $this->handler()->saveSubscription();
    }

    public function analyzeBoilerplate(): void
    {
        $this->handler()->analyzeBoilerplate();
    }

    public function analyzeSplitting(): void
    {
        $this->handler()->analyzeSplitting();
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

    public function moveToNewsletter(): void
    {
        $this->handler()->moveToOtherModule();
    }
}
