from django.core.cache import caches
from django.urls import reverse
from rest_framework.test import APIClient
from rest_framework.test import APITestCase

from kolibri.core.auth.models import Classroom
from kolibri.core.auth.models import Facility
from kolibri.core.auth.models import FacilityUser
from kolibri.core.device.models import DevicePermissions
from kolibri.core.device.utils import provision_device

PASSWORD = "a-password-only-the-test-knows"


class DelegatedSessionTest(APITestCase):
    databases = "__all__"

    @classmethod
    def setUpTestData(cls):
        # Kolibri caches the provisioned state on disk, across test runs.
        for cache in caches.all():
            cache.clear()
        provision_device()
        cls.facility = Facility.objects.create(name="Sunrise")
        cls.other_facility = Facility.objects.create(name="Elsewhere")
        cls.superuser = cls._user("device-admin", cls.facility)
        DevicePermissions.objects.create(user=cls.superuser, is_superuser=True)
        cls.admin = cls._user("facility-admin", cls.facility)
        cls.facility.add_admin(cls.admin)
        cls.coach = cls._user("coach", cls.facility)
        cls.classroom = Classroom.objects.create(name="Grade 1", parent=cls.facility)
        cls.classroom.add_coach(cls.coach)
        cls.learner = cls._user("learner", cls.facility)
        cls.other_learner = cls._user("other-learner", cls.facility)
        cls.foreign_learner = cls._user("foreign-learner", cls.other_facility)

    @staticmethod
    def _user(username, facility):
        user = FacilityUser(username=username, facility=facility)
        user.set_password(PASSWORD)
        user.save()
        return user

    def _mint(self, caller, target):
        if caller is not None:
            self.client.login(
                username=caller.username, password=PASSWORD, facility=caller.facility
            )
        return self.client.post(
            reverse("kolibri:kolibri_delegated_session:delegated_session"),
            {"user": target.id},
            format="json",
        )

    def _whoami(self, minted):
        browser = APIClient()
        browser.cookies[minted["session"]["cookie"]] = minted["session"]["value"]
        return browser.put(reverse("kolibri:core:session-detail", args=["current"]))

    def test_a_facility_admin_gets_a_session_that_is_the_learner(self):
        response = self._mint(self.admin, self.learner)

        self.assertEqual(response.status_code, 201)
        self.assertEqual(response.data["user_id"], self.learner.id)
        session = self._whoami(response.data).data
        self.assertEqual(session["user_id"], self.learner.id)
        self.assertEqual(session["kind"], ["learner"])

    def test_a_device_superuser_may_delegate_too(self):
        self.assertEqual(self._mint(self.superuser, self.learner).status_code, 201)

    def test_the_admin_keeps_their_own_session(self):
        self._mint(self.admin, self.learner)

        current = self.client.put(reverse("kolibri:core:session-detail", args=["current"]))
        self.assertEqual(current.data["user_id"], self.admin.id)

    def test_the_minted_session_can_log_progress_with_its_csrf_token(self):
        minted = self._mint(self.admin, self.learner).data
        browser = APIClient(enforce_csrf_checks=True)
        browser.cookies[minted["session"]["cookie"]] = minted["session"]["value"]
        browser.cookies[minted["csrf"]["cookie"]] = minted["csrf"]["token"]

        response = browser.put(
            reverse("kolibri:core:session-detail", args=["current"]),
            {"active": True},
            format="json",
            HTTP_X_CSRFTOKEN=minted["csrf"]["token"],
        )

        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.data["user_id"], self.learner.id)

    def test_nobody_else_may_delegate(self):
        self.assertEqual(self._mint(None, self.learner).status_code, 403)
        for caller in (self.coach, self.other_learner):
            self.client.logout()
            self.assertEqual(self._mint(caller, self.learner).status_code, 403)

    def test_an_admin_cannot_reach_a_learner_of_another_facility(self):
        self.assertEqual(self._mint(self.admin, self.foreign_learner).status_code, 403)

    def test_a_session_is_only_minted_for_a_learner(self):
        for target in (self.coach, self.admin, self.superuser):
            response = self._mint(self.superuser, target)
            self.assertEqual(response.status_code, 403)
            self.assertEqual(response.data[0]["id"], "TARGET_IS_NOT_A_LEARNER")

    def test_an_unknown_user_is_not_found(self):
        self.client.login(
            username=self.admin.username, password=PASSWORD, facility=self.facility
        )
        url = reverse("kolibri:kolibri_delegated_session:delegated_session")
        for body in ({"user": "0" * 32}, {"user": "not-an-id"}, {}):
            self.assertEqual(self.client.post(url, body, format="json").status_code, 404)
