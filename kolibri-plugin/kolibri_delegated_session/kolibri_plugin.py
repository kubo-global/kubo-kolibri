from kolibri.plugins import KolibriPluginBase


class DelegatedSession(KolibriPluginBase):
    untranslated_view_urls = "api_urls"

    @property
    def url_slug(self):
        return "delegated-session"
