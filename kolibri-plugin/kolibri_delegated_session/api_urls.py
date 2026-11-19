from django.urls import re_path

from .api import DelegatedSessionView

urlpatterns = [
    re_path(r"^session/$", DelegatedSessionView.as_view(), name="delegated_session"),
]
